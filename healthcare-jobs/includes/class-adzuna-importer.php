<?php
/**
 * Import orchestration: Adzuna -> Directorist.
 *
 * Mirrors Healthcare_Jobs_Importer's TheirStack pipeline exactly (fetch,
 * classify, map, sync) but reads from the Adzuna Jobs API instead, so
 * Adzuna-sourced jobs land in Directorist through the identical mapper/
 * sync path as TheirStack jobs - same fields, same single-listing page
 * layout, same Company Website button whenever a company website happens
 * to be present.
 *
 * Adzuna's standard job search response does not include a company
 * website (only a `company.display_name`), so `_custom-url` stays empty
 * for Adzuna-sourced jobs and the Company Website button simply will not
 * appear on them - see Healthcare_Jobs_Directorist_Mapper::map().
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Adzuna_Importer {

	const LOCK_KEY      = 'healthcare_jobs_adzuna_import_lock';
	const LOCK_LIFETIME = 15 * MINUTE_IN_SECONDS;

	/**
	 * @var Healthcare_Jobs_Adzuna_API
	 */
	private $api;

	public function __construct() {
		$this->api = new Healthcare_Jobs_Adzuna_API();
	}

	/**
	 * True while an Adzuna import is currently in progress. A separate lock
	 * from Healthcare_Jobs_Importer::LOCK_KEY so a TheirStack and an Adzuna
	 * import can run back to back without one waiting on the other's lock.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		return (bool) get_transient( self::LOCK_KEY );
	}

	/**
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
	 * Runs a full Adzuna import. Safe to call from cron or from an admin
	 * button, same contract as Healthcare_Jobs_Importer::run().
	 *
	 * @param string $trigger_type 'manual' or 'cron'.
	 * @return array Result summary, or array with 'error' on failure to start.
	 */
	public function run( $trigger_type = 'manual' ) {
		if ( self::is_locked() ) {
			return array( 'error' => __( 'An Adzuna import is already running. Please wait for it to finish.', 'healthcare-jobs' ) );
		}

		set_transient( self::LOCK_KEY, time(), self::LOCK_LIFETIME );

		$log_id = Healthcare_Jobs_Logger::start_import( $trigger_type );

		$stats      = self::empty_stats();
		$errors     = array();
		$error_code = null;

		if ( ! Healthcare_Jobs_Settings::has_adzuna_credentials() ) {
			$errors[] = __( '[Adzuna] No App ID/App Key configured.', 'healthcare-jobs' );
			Healthcare_Jobs_Logger::finish_import( $log_id, self::to_legacy_log_stats( $stats ), 'failed', $errors );
			delete_transient( self::LOCK_KEY );
			return array( 'error' => $errors[0], 'stats' => $stats, 'error_code' => 'healthcare_jobs_adzuna_no_credentials', 'stage' => 'authentication' );
		}

		try {
			$this->fetch_and_import( $stats, $errors, $error_code );
		} catch ( \Throwable $e ) {
			$errors[] = '[Adzuna] Unexpected error: ' . $e->getMessage();
			Healthcare_Jobs_Logger::debug( 'Adzuna importer exception: ' . $e->getMessage() );
		}

		$status = 'success';
		if ( $stats['jobs_failed'] > 0 && 0 === $stats['jobs_created'] && 0 === $stats['jobs_updated'] ) {
			$status = 'failed';
		} elseif ( ! empty( $errors ) || $stats['jobs_failed'] > 0 ) {
			$status = ( $stats['jobs_created'] > 0 || $stats['jobs_updated'] > 0 ) ? 'partial' : 'failed';
		}

		Healthcare_Jobs_Logger::finish_import( $log_id, self::to_legacy_log_stats( $stats ), $status, $errors );

		delete_transient( self::LOCK_KEY );

		$auth_stage_codes = array(
			'healthcare_jobs_adzuna_no_credentials',
			'healthcare_jobs_adzuna_auth_failed',
			'healthcare_jobs_adzuna_rate_limited',
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
	 * import-log table already has, same as Healthcare_Jobs_Importer.
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
	 *                                the first request-level failure, if any.
	 * @return void
	 */
	private function fetch_and_import( array &$stats, array &$errors, &$error_code = null ) {
		$settings = Healthcare_Jobs_Settings::get_all();

		$job_titles = Healthcare_Jobs_Categories::get_all_titles();
		if ( empty( $job_titles ) ) {
			$errors[] = __( '[Adzuna] No healthcare job titles are configured; nothing to search for.', 'healthcare-jobs' );
			return;
		}

		$country_code = strtoupper( (string) $settings['default_country'] );

		$max_jobs  = max( 1, (int) $settings['max_jobs_per_import'] );
		$page_size = min( Healthcare_Jobs_Adzuna_API::get_max_page_size(), $max_jobs );

		$page          = 1;
		$fetched_total = 0;

		do {
			$remaining = $max_jobs - $fetched_total;
			$limit     = max( 1, min( $page_size, $remaining ) );

			$params = $this->api->build_search_params(
				array(
					'job_titles'   => $job_titles,
					'max_age_days' => (int) $settings['default_job_age_days'],
					'limit'        => $limit,
				)
			);

			$response = $this->api->search_jobs(
				array(
					'country_code' => $country_code,
					'page'         => $page,
					'params'       => $params,
				)
			);

			if ( is_wp_error( $response ) ) {
				$errors[] = '[Adzuna] ' . $response->get_error_message();
				if ( null === $error_code ) {
					$error_code = $response->get_error_code();
				}
				break;
			}

			$jobs  = $this->api->extract_jobs( $response );
			$count = count( $jobs );

			if ( 0 === $count ) {
				if ( 1 === $page ) {
					$errors[] = sprintf(
						/* translators: 1: request parameters as JSON, 2: total_results reported by the API, if any */
						__( '[Adzuna] Returned zero jobs for this request. Sent: %1$s. API-reported count: %2$s.', 'healthcare-jobs' ),
						wp_json_encode( $params ),
						null === $this->api->extract_total_results( $response ) ? 'n/a' : $this->api->extract_total_results( $response )
					);
				}
				break;
			}

			$stats['jobs_found'] += $count;

			foreach ( $jobs as $raw_job ) {
				$this->import_one( $raw_job, $country_code, $stats, $errors );
			}

			$fetched_total += $count;
			++$page;

			if ( $count < $limit ) {
				break;
			}
		} while ( $fetched_total < $max_jobs );
	}

	/**
	 * Classifies, maps, and syncs a single raw Adzuna job record into
	 * Directorist. Failures here only skip this one record.
	 *
	 * @param array  $raw          Raw job object from the API.
	 * @param string $country_code The country this search ran against (Adzuna's
	 *                              endpoint is per-country, so this is exact,
	 *                              unlike guessing it from the job record).
	 * @param array  $stats        Reference to running stats.
	 * @param array  $errors       Reference to running errors.
	 * @return void
	 */
	private function import_one( array $raw, $country_code, array &$stats, array &$errors ) {
		$external_id = self::extract( $raw, array( 'id' ) );
		$title       = trim( (string) self::extract( $raw, array( 'title' ) ) );
		$description = (string) self::extract( $raw, array( 'description' ) );

		if ( empty( $external_id ) || '' === $title ) {
			++$stats['jobs_skipped'];
			$errors[] = sprintf(
				/* translators: %s: raw job identifier or "unknown" */
				__( '[Adzuna] [validation] Skipped a job with missing ID or title (%s).', 'healthcare-jobs' ),
				empty( $external_id ) ? 'unknown id' : $external_id
			);
			return;
		}

		// Prefixed so Adzuna's own numeric ID can never collide with a
		// TheirStack (or any other source's) external_job_id in the shared
		// postmeta dedupe lookup (Healthcare_Jobs_Directorist_Sync::find_existing_post_id()).
		$external_id = 'adzuna-' . $external_id;

		$classification = Healthcare_Jobs_Classifier::classify( $title, $description );

		if ( 0 === $classification['term_id'] ) {
			++$stats['jobs_skipped'];
			$errors[] = sprintf(
				/* translators: %s: job title */
				__( '[Adzuna] [classification] Skipped "%s" - did not match a configured healthcare category.', 'healthcare-jobs' ),
				$title
			);
			return;
		}

		$area   = self::extract( $raw, array( 'location.area' ), array() );
		$area   = is_array( $area ) ? array_values( array_filter( $area, 'strlen' ) ) : array();
		$city   = ! empty( $area ) ? (string) end( $area ) : '';
		$region = count( $area ) > 1 ? (string) $area[ count( $area ) - 2 ] : '';

		$salary_min = self::extract( $raw, array( 'salary_min' ), null );
		$salary_max = self::extract( $raw, array( 'salary_max' ), null );

		$job_data = array(
			'external_job_id'  => $external_id,
			'source'           => 'adzuna',
			'source_url'       => esc_url_raw( (string) self::extract( $raw, array( 'redirect_url' ) ) ),
			'title'            => sanitize_text_field( $title ),
			'description'      => wp_kses_post( $description ),
			'company_name'     => sanitize_text_field( (string) self::extract( $raw, array( 'company.display_name' ) ) ),
			// Adzuna's job search response has no company website field,
			// only a display name - left empty on purpose, see class doc.
			'company_website'  => '',
			'company_logo_url' => '',
			'location'         => sanitize_text_field( (string) self::extract( $raw, array( 'location.display_name' ) ) ),
			'city'             => sanitize_text_field( $city ),
			'region'           => sanitize_text_field( $region ),
			'postcode'         => '',
			'country'          => '',
			'country_code'     => substr( $country_code, 0, 2 ),
			'employment_type'  => sanitize_text_field( (string) self::extract( $raw, array( 'contract_time', 'contract_type' ) ) ),
			'remote_type'      => '',
			'salary_min'       => is_numeric( $salary_min ) ? (int) round( (float) $salary_min ) : null,
			'salary_max'       => is_numeric( $salary_max ) ? (int) round( (float) $salary_max ) : null,
			'salary_currency'  => null,
			'posted_at'        => self::parse_date( self::extract( $raw, array( 'created' ) ) ),
			'closing_date'     => null,
			// Adzuna's search response never reports a job as closed - a
			// listing simply stops reappearing in later searches. Every
			// result is treated as open; Directorist's own listing expiry
			// (Healthcare_Jobs_Directorist_Mapper::compute_expiry_date())
			// handles staleness the same way it does for TheirStack jobs
			// that fall out of the feed.
			'is_closed'        => false,
			'raw_data'         => $raw,
		);

		/** This filter is documented in class-importer.php. */
		$job_data = apply_filters( 'healthcare_jobs_map_job', $job_data, $raw );

		$result = Healthcare_Jobs_Directorist_Sync::sync( $job_data, $classification['term_id'] );

		if ( $result['error'] ) {
			++$stats['jobs_failed'];
			$errors[] = sprintf(
				/* translators: 1: job title, 2: error message */
				__( '[Adzuna] [directorist-sync] Failed to save "%1$s": %2$s', 'healthcare-jobs' ),
				$title,
				$result['error']
			);
			return;
		}

		if ( $result['is_new'] ) {
			++$stats['jobs_created'];
		} else {
			++$stats['jobs_updated'];
		}
	}

	/**
	 * Reads a value out of a raw array by trying several possible keys in
	 * order, including simple dot-paths for one level of nesting. Mirrors
	 * Healthcare_Jobs_Importer::extract() exactly.
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
