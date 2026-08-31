<?php
/**
 * Import orchestration: TheirStack -> local database.
 *
 * Fetches paginated results from TheirStack, maps each record onto the
 * local schema, deduplicates against wp_healthcare_jobs, and writes an
 * import log entry. A transient lock prevents two imports (manual + cron,
 * or two overlapping cron ticks) from running at the same time.
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

		$stats = array(
			'jobs_found'    => 0,
			'jobs_imported' => 0,
			'jobs_updated'  => 0,
			'jobs_skipped'  => 0,
			'jobs_expired'  => 0,
		);
		$errors = array();

		if ( ! Healthcare_Jobs_Settings::has_api_key() ) {
			$errors[] = __( 'No TheirStack API key configured.', 'healthcare-jobs' );
			Healthcare_Jobs_Logger::finish_import( $log_id, $stats, 'failed', $errors );
			delete_transient( self::LOCK_KEY );
			return array( 'error' => $errors[0], 'stats' => $stats );
		}

		try {
			$this->fetch_and_import( $stats, $errors );

			$settings              = Healthcare_Jobs_Settings::get_all();
			$stats['jobs_expired'] = Healthcare_Jobs_Jobs::expire_by_max_age( $settings['default_job_age_days'] )
				+ Healthcare_Jobs_Jobs::expire_unseen( max( 2, (int) $settings['default_job_age_days'] ) );
		} catch ( \Throwable $e ) {
			$errors[] = 'Unexpected error: ' . $e->getMessage();
			Healthcare_Jobs_Logger::debug( 'Importer exception: ' . $e->getMessage() );
		}

		$status = 'success';
		if ( ! empty( $errors ) ) {
			$status = ( $stats['jobs_imported'] > 0 || $stats['jobs_updated'] > 0 ) ? 'partial' : 'failed';
		}

		Healthcare_Jobs_Logger::finish_import( $log_id, $stats, $status, $errors );

		delete_transient( self::LOCK_KEY );

		return array( 'stats' => $stats, 'status' => $status, 'errors' => $errors );
	}

	/**
	 * Fetches all pages up to the configured maximum and imports each job.
	 *
	 * @param array $stats  Reference to the running stats array.
	 * @param array $errors Reference to the running errors array.
	 * @return void
	 */
	private function fetch_and_import( array &$stats, array &$errors ) {
		$settings = Healthcare_Jobs_Settings::get_all();

		$job_titles = Healthcare_Jobs_Categories::get_all_titles();
		if ( empty( $job_titles ) ) {
			$errors[] = __( 'No healthcare job titles are configured; nothing to search for.', 'healthcare-jobs' );
			return;
		}

		$max_jobs  = max( 1, (int) $settings['max_jobs_per_import'] );
		$page_size = min( Healthcare_Jobs_TheirStack_API::MAX_PAGE_SIZE, $max_jobs );

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
				break;
			}

			$jobs = $this->api->extract_jobs( $response );
			$count = count( $jobs );

			if ( 0 === $count ) {
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
	 * Maps and imports a single raw TheirStack job record. Failures here
	 * only skip this one record; they never abort the whole run.
	 *
	 * @param array $raw    Raw job object from the API.
	 * @param array $stats  Reference to running stats.
	 * @param array $errors Reference to running errors.
	 * @return void
	 */
	private function import_one( array $raw, array &$stats, array &$errors ) {
		$external_id = self::extract( $raw, array( 'id', 'job_id', 'uuid' ) );
		$title       = trim( (string) self::extract( $raw, array( 'title', 'job_title' ) ) );

		if ( empty( $external_id ) || '' === $title ) {
			++$stats['jobs_skipped'];
			$errors[] = sprintf(
				/* translators: %s: raw job identifier or "unknown" */
				__( 'Skipped a job with missing ID or title (%s).', 'healthcare-jobs' ),
				empty( $external_id ) ? 'unknown id' : $external_id
			);
			return;
		}

		$category = self::extract( $raw, array( 'category' ) );
		if ( empty( $category ) ) {
			$category = Healthcare_Jobs_Categories::classify_title( $title );
		}

		if ( empty( $category ) ) {
			// The API's own title search is an OR match, so results can
			// still include titles outside our configured healthcare list.
			// Only store what we can classify.
			++$stats['jobs_skipped'];
			$errors[] = sprintf(
				/* translators: %s: job title */
				__( 'Skipped "%s" - does not match a configured healthcare job title.', 'healthcare-jobs' ),
				$title
			);
			return;
		}

		$is_active    = self::extract( $raw, array( 'is_active', 'active' ), null );
		$status_field = strtolower( (string) self::extract( $raw, array( 'status' ) ) );
		$is_closed    = ( false === $is_active ) || in_array( $status_field, array( 'closed', 'expired', 'filled' ), true );

		if ( $is_closed && 'open' === Healthcare_Jobs_Settings::get( 'default_job_status', 'open' ) ) {
			// "Open jobs only" is enforced locally rather than via an
			// unconfirmed TheirStack request parameter (see the API
			// client) - a closed job is simply not stored at all.
			++$stats['jobs_skipped'];
			return;
		}

		$company_data = array(
			'external_company_id' => (string) self::extract( $raw, array( 'company.id', 'company_id' ) ),
			'company_name'        => (string) self::extract( $raw, array( 'company.name', 'company_name' ) ),
			'website'             => (string) self::extract( $raw, array( 'company.domain', 'company.website', 'company_domain' ) ),
			'industry'            => (string) self::extract( $raw, array( 'company.industry', 'company_industry' ) ),
			'description'         => (string) self::extract( $raw, array( 'company.description', 'company_description' ) ),
			'location'            => (string) self::extract( $raw, array( 'company.location', 'company_location' ) ),
			'country'             => (string) self::extract( $raw, array( 'company.country', 'company_country' ) ),
			'logo_url'            => (string) self::extract( $raw, array( 'company.logo', 'company.logo_url', 'company_logo' ) ),
		);

		$company_id = Healthcare_Jobs_Companies::upsert( $company_data );

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

		$posted_at = self::parse_date( self::extract( $raw, array( 'posted_at', 'date_posted' ) ) );

		$country_code = strtoupper( (string) self::extract( $raw, array( 'country_code', 'job_country_code' ) ) );

		$job_data = array(
			'external_job_id'  => (string) $external_id,
			'source'           => 'theirstack',
			'job_source_type'  => 'aggregated',
			'source_url'       => esc_url_raw( (string) self::extract( $raw, array( 'final_url', 'url', 'job_url' ) ) ),
			'title'            => sanitize_text_field( $title ),
			'company_id'       => $company_id,
			'company_name'     => sanitize_text_field( $company_data['company_name'] ),
			'company_website'  => esc_url_raw( $company_data['website'] ),
			'description'      => wp_kses_post( (string) self::extract( $raw, array( 'description', 'job_description' ) ) ),
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
			'salary_currency'  => $salary_currency ? sanitize_text_field( strtoupper( $salary_currency ) ) : null,
			'category'         => sanitize_text_field( $category ),
			'specialty'        => sanitize_text_field( (string) self::extract( $raw, array( 'specialty' ) ) ),
			'seniority'        => sanitize_text_field( (string) self::extract( $raw, array( 'seniority' ) ) ),
			'employer_type'    => Healthcare_Jobs_Companies::classify_employer_type( $company_data['company_name'], $company_data ),
			'posted_at'        => $posted_at,
			'closing_date'     => self::parse_date( self::extract( $raw, array( 'closing_date', 'expires_at' ) ) ),
			'is_closed'        => $is_closed ? 1 : 0,
			'status'           => $is_closed ? Healthcare_Jobs_Jobs::STATUS_CLOSED : Healthcare_Jobs_Jobs::STATUS_ACTIVE,
			'raw_data'         => wp_json_encode( $raw ),
		);

		/**
		 * Filters the fully-mapped job record right before it is saved,
		 * letting a developer correct field mapping if TheirStack changes
		 * their schema without waiting for a plugin update.
		 *
		 * @param array $job_data Mapped job fields.
		 * @param array $raw      Original raw job payload.
		 */
		$job_data = apply_filters( 'healthcare_jobs_map_job', $job_data, $raw );

		$result = Healthcare_Jobs_Jobs::upsert( $job_data );

		Healthcare_Jobs_Companies::recalculate_active_jobs( $company_id );

		if ( $result['is_new'] ) {
			++$stats['jobs_imported'];
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
	 * this returns the first scalar value so it fits a single DB column.
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
