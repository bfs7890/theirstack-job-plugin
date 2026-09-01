<?php
/**
 * Import orchestration: TheirStack -> Directorist.
 *
 * Fetches paginated results from TheirStack, classifies each job into a
 * real Directorist category (Healthcare_Jobs_Classifier), maps it onto
 * Directorist's field schema (Healthcare_Jobs_Directorist_Mapper), and
 * writes it as a Directorist listing (Healthcare_Jobs_Directorist_Sync).
 * Directorist is the authoritative store; this class never keeps its own
 * copy of listing content. A transient lock prevents two imports (manual +
 * cron, or two overlapping cron ticks) from running at the same time.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Importer {

	const LOCK_KEY      = 'healthcare_jobs_import_lock';
	const LOCK_LIFETIME = 15 * MINUTE_IN_SECONDS;

	/**
	 * @var Healthcare_Jobs_TheirStack_API
	 */
	private $api;

	public function __construct() {
		$this->api = new Healthcare_Jobs_TheirStack_API();
	}

	/**
	 * True while an import is currently in progress.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		return (bool) get_transient( self::LOCK_KEY );
	}

	/**
	 * The stats keys every run reports, per the required admin summary:
	 * Found / Created in Directorist / Updated in Directorist / Skipped /
	 * Closed / Failed.
	 *
	 * @return array
	 */
	private static function empty_stats() {
		return array(
			'jobs_found'   => 0,
			'jobs_created' => 0,
			'jobs_updated' => 0,
			'jobs_skipped' => 0,
			'jobs_closed'  => 0,
			'jobs_failed'  => 0,
		);
	}

	/**
	 * Runs a full import. Safe to call from cron or from an admin button.
	 *
	 * @param string $trigger_type 'manual' or 'cron'.
	 * @return array Result summary, or array with 'error' on failure to start.
	 */
	public function run( $trigger_type = 'manual' ) {
		if ( self::is_locked() ) {
			return array( 'error' => __( 'An import is already running. Please wait for it to finish.', 'healthcare-jobs' ) );
		}

		set_transient( self::LOCK_KEY, time(), self::LOCK_LIFETIME );

		$log_id = Healthcare_Jobs_Logger::start_import( $trigger_type );

		$stats      = self::empty_stats();
		$errors     = array();
		$error_code = null;

		if ( ! Healthcare_Jobs_Settings::has_api_key() ) {
			$errors[] = __( 'No TheirStack API key configured.', 'healthcare-jobs' );
			Healthcare_Jobs_Logger::finish_import( $log_id, $stats, 'failed', $errors );
			delete_transient( self::LOCK_KEY );
			return array( 'error' => $errors[0], 'stats' => $stats, 'error_code' => 'healthcare_jobs_no_api_key', 'stage' => 'authentication' );
		}

		try {
			$this->fetch_and_import( $stats, $errors, $error_code );
		} catch ( \Throwable $e ) {
			$errors[] = 'Unexpected error: ' . $e->getMessage();
			Healthcare_Jobs_Logger::debug( 'Importer exception: ' . $e->getMessage() );
		}

		$status = 'success';
		if ( $stats['jobs_failed'] > 0 && 0 === $stats['jobs_created'] && 0 === $stats['jobs_updated'] ) {
			$status = 'failed';
		} elseif ( ! empty( $errors ) || $stats['jobs_failed'] > 0 ) {
			$status = ( $stats['jobs_created'] > 0 || $stats['jobs_updated'] > 0 ) ? 'partial' : 'failed';
		}

		Healthcare_Jobs_Logger::finish_import( $log_id, self::to_legacy_log_stats( $stats ), $status, $errors );

		delete_transient( self::LOCK_KEY );

		// Authentication/authorization/billing/request failures happen
		// before any job search could possibly have run, so the admin UI
		// must present them as a distinct failure stage - never as an
		// indistinguishable "Found: 0" as if a normal empty search ran.
		$auth_stage_codes = array(
			'healthcare_jobs_no_api_key',
			'healthcare_jobs_auth_failed',
			'healthcare_jobs_forbidden',
			'healthcare_jobs_payment_required',
			'healthcare_jobs_invalid_request',
			'healthcare_jobs_rate_limited',
		);
		$stage = in_array( $error_code, $auth_stage_codes, true ) ? 'authentication' : 'search';

		return array(
			'stats'      => $stats,
			'status'     => $status,
			'errors'     => $errors,
			'error_code' => $error_code,
			'stage'      => $stage,
		);
	}

	/**
	 * Maps the new stat keys onto the columns Healthcare_Jobs_Logger's
	 * import-log table already has (jobs_imported/jobs_expired), so the
	 * existing log schema doesn't need a migration of its own.
	 *
	 * @param array $stats New-style stats.
	 * @return array
	 */
	private static function to_legacy_log_stats( array $stats ) {
		return array(
			'jobs_found'    => $stats['jobs_found'],
			'jobs_imported' => $stats['jobs_created'],
			'jobs_updated'  => $stats['jobs_updated'],
			'jobs_skipped'  => $stats['jobs_skipped'],
			'jobs_expired'  => $stats['jobs_closed'],
		);
	}

	/**
	 * Fetches all pages up to the configured maximum and imports each job.
	 *
	 * @param array       $stats      Reference to the running stats array.
	 * @param array       $errors     Reference to the running errors array.
	 * @param string|null $error_code Reference set to the WP_Error code of
	 *                                the first request-level failure, if any
	 *                                (as opposed to a per-job skip), so the
	 *                                caller can tell an authentication/API
	 *                                failure apart from a normal empty result.
	 * @return void
	 */
	private function fetch_and_import( array &$stats, array &$errors, &$error_code = null ) {
		$settings = Healthcare_Jobs_Settings::get_all();

		$job_titles = Healthcare_Jobs_Categories::get_all_titles();
		if ( empty( $job_titles ) ) {
			$errors[] = __( 'No healthcare job titles are configured; nothing to search for.', 'healthcare-jobs' );
			return;
		}

		$max_jobs  = max( 1, (int) $settings['max_jobs_per_import'] );
		$page_size = min( Healthcare_Jobs_TheirStack_API::get_max_page_size(), $max_jobs );

		$page          = 0;
		$fetched_total = 0;

		do {
			$remaining = $max_jobs - $fetched_total;
			$limit     = max( 1, min( $page_size, $remaining ) );

			$params = $this->api->build_search_params(
				array(
					'country_code' => $settings['default_country'],
					'max_age_days' => (int) $settings['default_job_age_days'],
					'job_titles'   => $job_titles,
					'page'         => $page,
					'limit'        => $limit,
				)
			);

			$response = $this->api->search_jobs( $params );

			if ( is_wp_error( $response ) ) {
				$errors[] = $response->get_error_message();
				if ( null === $error_code ) {
					$error_code = $response->get_error_code();
				}
				break;
			}

			$jobs  = $this->api->extract_jobs( $response );
			$count = count( $jobs );

			if ( 0 === $count ) {
				if ( 0 === $page ) {
					// A 200 response with zero jobs is not necessarily an
					// error, but it is exactly the kind of silent failure
					// an admin cannot otherwise diagnose - e.g. a rejected
					// filter combination some APIs report as an empty
					// result set instead of an HTTP error. Record what was
					// actually sent/received (no secrets in either) so
					// Import History gives a real starting point.
					$errors[] = sprintf(
						/* translators: 1: request parameters as JSON, 2: total_results reported by the API, if any */
						__( 'TheirStack returned zero jobs for this request. Sent: %1$s. API-reported total_results: %2$s.', 'healthcare-jobs' ),
						wp_json_encode( $params ),
						null === $this->api->extract_total_results( $response ) ? 'n/a' : $this->api->extract_total_results( $response )
					);
				}
				break;
			}

			$stats['jobs_found'] += $count;

			foreach ( $jobs as $raw_job ) {
				$this->import_one( $raw_job, $stats, $errors );
			}

			$fetched_total += $count;
			++$page;

			// Stop once a short page tells us there is nothing further.
			if ( $count < $limit ) {
				break;
			}
		} while ( $fetched_total < $max_jobs );
	}

	/**
	 * Classifies, maps, and syncs a single raw TheirStack job record into
	 * Directorist. Failures here only skip this one record; they never
	 * abort the whole run, and each failure records which stage it
	 * happened at (classification, mapping, or Directorist sync).
	 *
	 * @param array $raw    Raw job object from the API.
	 * @param array $stats  Reference to running stats.
	 * @param array $errors Reference to running errors.
	 * @return void
	 */
	private function import_one( array $raw, array &$stats, array &$errors ) {
		$external_id = self::extract( $raw, array( 'id', 'job_id', 'uuid' ) );
		$title       = trim( (string) self::extract( $raw, array( 'title', 'job_title' ) ) );
		$description = (string) self::extract( $raw, array( 'description', 'job_description' ) );

		if ( empty( $external_id ) || '' === $title ) {
			++$stats['jobs_skipped'];
			$errors[] = sprintf(
				/* translators: %s: raw job identifier or "unknown" */
				__( '[validation] Skipped a job with missing ID or title (%s).', 'healthcare-jobs' ),
				empty( $external_id ) ? 'unknown id' : $external_id
			);
			return;
		}

		$classification = Healthcare_Jobs_Classifier::classify( $title, $description );

		if ( 0 === $classification['term_id'] ) {
			// The API's own title search is an OR match, so results can
			// still include titles outside our configured healthcare list,
			// or titles that matched a search term but failed the
			// classifier's context/exclusion checks (e.g. "IT Consultant").
			// Only store what we can confidently classify into a real
			// Directorist category.
			++$stats['jobs_skipped'];
			$errors[] = sprintf(
				/* translators: %s: job title */
				__( '[classification] Skipped "%s" - did not match a configured healthcare category.', 'healthcare-jobs' ),
				$title
			);
			return;
		}

		$is_active    = self::extract( $raw, array( 'is_active', 'active' ), null );
		$status_field = strtolower( (string) self::extract( $raw, array( 'status' ) ) );
		$is_closed    = ( false === $is_active ) || in_array( $status_field, array( 'closed', 'expired', 'filled' ), true );

		$existing_post_id = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( (string) $external_id );

		if ( $is_closed && ! $existing_post_id && 'open' === Healthcare_Jobs_Settings::get( 'default_job_status', 'open' ) ) {
			// "Open jobs only" governs new listings: don't create a fresh
			// Directorist listing for a job that's already closed. A job we
			// already imported and is now reported closed still needs to be
			// synced below so its existing listing gets marked closed.
			++$stats['jobs_skipped'];
			return;
		}

		$remote_flag = self::extract( $raw, array( 'remote' ), null );
		$remote_type = '';
		if ( true === $remote_flag ) {
			$remote_type = 'remote';
		} elseif ( false === $remote_flag ) {
			$remote_type = 'onsite';
		}
		$remote_pattern = strtolower( (string) self::extract( $raw, array( 'remote_pattern', 'workplace_type' ) ) );
		if ( false !== strpos( $remote_pattern, 'hybrid' ) ) {
			$remote_type = 'hybrid';
		}

		$salary_currency = self::extract( $raw, array( 'salary_currency' ) );
		$salary_min      = self::extract( $raw, array( 'min_annual_salary', 'min_salary_usd' ) );
		$salary_max      = self::extract( $raw, array( 'max_annual_salary', 'max_salary_usd' ) );
		if ( empty( $salary_currency ) && null !== self::extract( $raw, array( 'min_salary_usd', 'max_salary_usd' ) ) ) {
			$salary_currency = 'USD';
		}

		$country_code = strtoupper( (string) self::extract( $raw, array( 'country_code', 'job_country_code' ) ) );

		$job_data = array(
			'external_job_id'  => (string) $external_id,
			'source'           => 'theirstack',
			'source_url'       => esc_url_raw( (string) self::extract( $raw, array( 'final_url', 'url', 'job_url' ) ) ),
			'title'            => sanitize_text_field( $title ),
			'description'      => wp_kses_post( $description ),
			'company_name'     => sanitize_text_field( (string) self::extract( $raw, array( 'company.name', 'company_name' ) ) ),
			'company_website'  => esc_url_raw( (string) self::extract( $raw, array( 'company.domain', 'company.website', 'company_domain' ) ) ),
			'company_logo_url' => esc_url_raw( (string) self::extract( $raw, array( 'company.logo', 'company.logo_url', 'company_logo' ) ) ),
			'location'         => sanitize_text_field( (string) self::extract( $raw, array( 'location', 'job_location' ) ) ),
			'city'             => sanitize_text_field( (string) self::extract( $raw, array( 'city' ) ) ),
			'region'           => sanitize_text_field( (string) self::extract( $raw, array( 'region', 'state' ) ) ),
			'postcode'         => sanitize_text_field( (string) self::extract( $raw, array( 'postal_code', 'postcode' ) ) ),
			'country'          => sanitize_text_field( (string) self::extract( $raw, array( 'country' ) ) ),
			'country_code'     => substr( $country_code, 0, 2 ),
			'employment_type'  => sanitize_text_field( (string) self::first_scalar( self::extract( $raw, array( 'employment_statuses', 'employment_type', 'job_employment_type' ) ) ) ),
			'remote_type'      => $remote_type,
			'salary_min'       => is_numeric( $salary_min ) ? (int) round( (float) $salary_min ) : null,
			'salary_max'       => is_numeric( $salary_max ) ? (int) round( (float) $salary_max ) : null,
			'salary_currency'  => $salary_currency ? strtoupper( sanitize_text_field( $salary_currency ) ) : null,
			'posted_at'        => self::parse_date( self::extract( $raw, array( 'posted_at', 'date_posted' ) ) ),
			'closing_date'     => self::parse_date( self::extract( $raw, array( 'closing_date', 'expires_at' ) ) ),
			'is_closed'        => $is_closed,
			'raw_data'         => $raw,
		);

		/**
		 * Filters the fully-mapped job record right before it is synced to
		 * Directorist, letting a developer correct field mapping if
		 * TheirStack changes their schema without waiting for a plugin
		 * update.
		 *
		 * @param array $job_data Mapped job fields.
		 * @param array $raw      Original raw job payload.
		 */
		$job_data = apply_filters( 'healthcare_jobs_map_job', $job_data, $raw );

		$result = Healthcare_Jobs_Directorist_Sync::sync( $job_data, $classification['term_id'] );

		if ( $result['error'] ) {
			++$stats['jobs_failed'];
			$errors[] = sprintf(
				/* translators: 1: job title, 2: error message */
				__( '[directorist-sync] Failed to save "%1$s": %2$s', 'healthcare-jobs' ),
				$title,
				$result['error']
			);
			return;
		}

		if ( $is_closed ) {
			++$stats['jobs_closed'];
		} elseif ( $result['is_new'] ) {
			++$stats['jobs_created'];
		} else {
			++$stats['jobs_updated'];
		}
	}

	/**
	 * Reads a value out of a raw array by trying several possible keys in
	 * order, including simple dot-paths for one level of nesting
	 * (e.g. 'company.name' checks $raw['company']['name']).
	 *
	 * @param array $raw      Source array.
	 * @param array $keys     Candidate keys, in priority order.
	 * @param mixed $fallback Value if none of the keys are set.
	 * @return mixed
	 */
	private static function extract( array $raw, array $keys, $fallback = '' ) {
		foreach ( $keys as $key ) {
			if ( false !== strpos( $key, '.' ) ) {
				list( $parent, $child ) = explode( '.', $key, 2 );
				if ( isset( $raw[ $parent ] ) && is_array( $raw[ $parent ] ) && array_key_exists( $child, $raw[ $parent ] ) && null !== $raw[ $parent ][ $child ] ) {
					return $raw[ $parent ][ $child ];
				}
				continue;
			}
			if ( array_key_exists( $key, $raw ) && null !== $raw[ $key ] && '' !== $raw[ $key ] ) {
				return $raw[ $key ];
			}
		}
		return $fallback;
	}

	/**
	 * Some TheirStack fields (e.g. employment_statuses) may be an array;
	 * this returns the first scalar value so it fits a single postmeta value.
	 *
	 * @param mixed $value Value that may be an array or scalar.
	 * @return string
	 */
	private static function first_scalar( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value[0] ) ? (string) $value[0] : '';
		}
		return (string) $value;
	}

	/**
	 * Parses a date string from the API into MySQL DATETIME (UTC), or null.
	 *
	 * @param mixed $value Raw date value.
	 * @return string|null
	 */
	private static function parse_date( $value ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return null;
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
