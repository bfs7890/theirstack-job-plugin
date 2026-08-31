<?php
/**
 * TheirStack Jobs API client.
 *
 * Wraps the TheirStack "Search Jobs" endpoint (POST /v1/jobs/search),
 * documented at https://theirstack.com/en/docs/api-reference/jobs/search_jobs_v1
 *
 * The API bills one credit per job returned, so every call here is built to
 * request only what the importer actually needs (bounded page size, a
 * required freshness filter, and administrator-configured limits).
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_TheirStack_API {

	const API_BASE = 'https://api.theirstack.com/v1';

	/**
	 * Maximum jobs TheirStack allows per page (per their published API docs).
	 *
	 * @var int
	 */
	const MAX_PAGE_SIZE = 500;

	/**
	 * Sends a single page request to POST /v1/jobs/search.
	 *
	 * @param array $params Request body. See build_search_params().
	 * @return array|WP_Error Decoded response body on success.
	 */
	public function search_jobs( array $params ) {
		$api_key = Healthcare_Jobs_Settings::get_api_key();

		// Temporary, safe diagnostics for the auth path shared by "Test API
		// Connection" and every import request - never the real key, only
		// its presence/length and a masked tail, written to the PHP error
		// log only when WP_DEBUG_LOG is enabled (see Healthcare_Jobs_Logger).
		Healthcare_Jobs_Logger::debug(
			sprintf(
				'TheirStack request -> POST %s | key_present=%s key_length=%d authorization=%s',
				self::API_BASE . '/jobs/search',
				empty( $api_key ) ? 'NO' : 'YES',
				strlen( (string) $api_key ),
				empty( $api_key ) ? 'none' : ( 'Bearer ' . str_repeat( '*', max( 0, strlen( $api_key ) - 4 ) ) . substr( $api_key, -4 ) )
			)
		);

		if ( empty( $api_key ) ) {
			return new WP_Error( 'healthcare_jobs_no_api_key', __( 'No TheirStack API key is configured.', 'healthcare-jobs' ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/jobs/search',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $params ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Healthcare_Jobs_Logger::debug( 'TheirStack request failed: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		Healthcare_Jobs_Logger::debug(
			sprintf(
				'TheirStack response <- status=%d jobs_returned=%s body_preview=%s',
				$code,
				( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) ? count( $data['data'] ) : 'n/a',
				substr( (string) $body, 0, 300 )
			)
		);

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'healthcare_jobs_auth_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: whether a key was present, 3: key length, 4: masked key tail */
					__( 'TheirStack rejected the API key (HTTP %1$d). Check the key in Settings. [key present: %2$s, length: %3$d, ends with: %4$s]', 'healthcare-jobs' ),
					$code,
					empty( $api_key ) ? 'no' : 'yes',
					strlen( (string) $api_key ),
					empty( $api_key ) ? 'n/a' : substr( $api_key, -4 )
				)
			);
		}

		if ( 429 === $code ) {
			return new WP_Error( 'healthcare_jobs_rate_limited', __( 'TheirStack API rate limit reached. Try again later.', 'healthcare-jobs' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : sprintf(
				/* translators: %d: HTTP status code */
				__( 'TheirStack API returned an unexpected error (HTTP %d).', 'healthcare-jobs' ),
				$code
			);
			return new WP_Error( 'healthcare_jobs_api_error', $message );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'healthcare_jobs_bad_response', __( 'TheirStack API returned an unreadable response.', 'healthcare-jobs' ) );
		}

		return $data;
	}

	/**
	 * Builds a TheirStack search payload from plugin-level filter options.
	 *
	 * @param array $args {
	 *     @type string   $country_code   ISO-2 country code, e.g. 'GB'.
	 *     @type int      $max_age_days   Only jobs posted within this many days.
	 *     @type string[] $job_titles     Job title OR list.
	 *     @type int      $page           0-indexed page number.
	 *     @type int      $limit          Page size (capped at MAX_PAGE_SIZE).
	 *     @type string   $discovered_after ISO8601 timestamp for incremental fetch.
	 * }
	 * @return array
	 */
	public function build_search_params( array $args ) {
		$params = array(
			'page'  => isset( $args['page'] ) ? max( 0, (int) $args['page'] ) : 0,
			'limit' => isset( $args['limit'] ) ? max( 1, min( self::MAX_PAGE_SIZE, (int) $args['limit'] ) ) : 100,
		);

		if ( ! empty( $args['country_code'] ) ) {
			$params['job_country_code_or'] = array( strtoupper( $args['country_code'] ) );
		}

		if ( ! empty( $args['job_titles'] ) && is_array( $args['job_titles'] ) ) {
			$params['job_title_or'] = array_values( array_filter( array_map( 'strval', $args['job_titles'] ) ) );
		}

		// TheirStack requires at least one "freshness" or company filter per
		// request; posted_at_max_age_days also doubles as our staleness cap.
		$params['posted_at_max_age_days'] = isset( $args['max_age_days'] ) ? max( 0, (int) $args['max_age_days'] ) : 30;

		if ( ! empty( $args['discovered_after'] ) ) {
			$params['discovered_at_gte'] = $args['discovered_after'];
		}

		// Deliberately not sending an "open jobs only" filter to the API:
		// TheirStack's exact parameter name for this is not confirmed, and
		// sending an unverified field risks silently mismatching results.
		// Instead every job is fetched and "open jobs only" is applied
		// locally in the importer, using the per-job status TheirStack
		// does document in its response (see Healthcare_Jobs_Importer).

		/**
		 * Filters the outgoing TheirStack search payload before it is sent.
		 *
		 * @param array $params Request body about to be JSON-encoded.
		 * @param array $args   Original plugin-level arguments.
		 */
		return apply_filters( 'healthcare_jobs_theirstack_search_params', $params, $args );
	}

	/**
	 * Runs a minimal, low-cost request purely to validate the configured
	 * API key and connectivity, for the admin "Test API Connection" button.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$params = $this->build_search_params(
			array(
				'country_code' => 'GB',
				'max_age_days' => 1,
				'limit'        => 1,
				'page'         => 0,
			)
		);

		$result = $this->search_jobs( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! array_key_exists( 'data', $result ) ) {
			return new WP_Error( 'healthcare_jobs_bad_response', __( 'Connected, but the response did not look like a TheirStack jobs response.', 'healthcare-jobs' ) );
		}

		return true;
	}

	/**
	 * Extracts the list of job records from a decoded search response,
	 * tolerating minor response-shape differences.
	 *
	 * @param array $response Decoded API response.
	 * @return array
	 */
	public function extract_jobs( array $response ) {
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}
		if ( isset( $response['jobs'] ) && is_array( $response['jobs'] ) ) {
			return $response['jobs'];
		}
		return array();
	}

	/**
	 * Reads total-results metadata when present, for progress reporting.
	 *
	 * @param array $response Decoded API response.
	 * @return int|null
	 */
	public function extract_total_results( array $response ) {
		if ( isset( $response['metadata']['total_results'] ) ) {
			return (int) $response['metadata']['total_results'];
		}
		return null;
	}
}
