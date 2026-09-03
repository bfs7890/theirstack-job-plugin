<?php
/**
 * Adzuna Jobs API client.
 *
 * Wraps Adzuna's "Search" endpoint
 * (GET /v1/api/jobs/{country}/search/{page}), documented at
 * https://developer.adzuna.com/docs/search
 *
 * Adzuna differs from TheirStack in two ways this class handles so nothing
 * else has to: authentication is a query-string app_id/app_key pair rather
 * than a bearer token, and the country is part of the URL path from a
 * fixed list of countries Adzuna covers, rather than an arbitrary ISO
 * code accepted in the request body.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Adzuna_API {

	const API_BASE = 'https://api.adzuna.com/v1/api/jobs';

	/**
	 * Maximum jobs per page Adzuna's search endpoint accepts.
	 *
	 * @var int
	 */
	const MAX_PAGE_SIZE = 50;

	/**
	 * Returns the effective maximum page size (MAX_PAGE_SIZE, unless
	 * overridden via the `healthcare_jobs_adzuna_max_page_size` filter).
	 *
	 * @return int
	 */
	public static function get_max_page_size() {
		return (int) apply_filters( 'healthcare_jobs_adzuna_max_page_size', self::MAX_PAGE_SIZE );
	}

	/**
	 * Sends a single page request to GET /v1/api/jobs/{country}/search/{page}.
	 *
	 * @param array $args {
	 *     @type string $country_code ISO-2 country code Adzuna covers, e.g. 'GB'.
	 *     @type int    $page         1-indexed page number.
	 *     @type array  $params       Additional query params, see build_search_params().
	 * }
	 * @return array|WP_Error Decoded response body on success.
	 */
	public function search_jobs( array $args ) {
		$app_id  = Healthcare_Jobs_Settings::get_adzuna_app_id();
		$app_key = Healthcare_Jobs_Settings::get_adzuna_app_key();

		if ( empty( $app_id ) || empty( $app_key ) ) {
			return new WP_Error( 'healthcare_jobs_adzuna_no_credentials', __( 'No Adzuna App ID/App Key is configured.', 'healthcare-jobs' ) );
		}

		$country = strtolower( sanitize_text_field( (string) ( $args['country_code'] ?? 'gb' ) ) );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );

		$params = array_merge(
			array(
				'app_id'       => $app_id,
				'app_key'      => $app_key,
				'content-type' => 'application/json',
			),
			isset( $args['params'] ) && is_array( $args['params'] ) ? $args['params'] : array()
		);

		$path = self::API_BASE . '/' . rawurlencode( $country ) . '/search/' . $page;
		$url  = $path . '?' . http_build_query( $params, '', '&' );

		Healthcare_Jobs_Logger::debug(
			sprintf(
				'Adzuna request -> GET %s | app_id_present=%s app_key_present=%s',
				$path,
				empty( $app_id ) ? 'NO' : 'YES',
				empty( $app_key ) ? 'NO' : 'YES'
			)
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Healthcare_Jobs_Logger::debug( 'Adzuna request failed: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		Healthcare_Jobs_Logger::debug(
			sprintf(
				'Adzuna response <- status=%d jobs_returned=%s body_preview=%s',
				$code,
				( is_array( $data ) && isset( $data['results'] ) && is_array( $data['results'] ) ) ? count( $data['results'] ) : 'n/a',
				substr( (string) $body, 0, 300 )
			)
		);

		$api_message = self::extract_api_message( $data, $body );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'healthcare_jobs_adzuna_auth_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: Adzuna's response text */
					__( 'Adzuna rejected the request: the App ID/App Key is missing or invalid (HTTP %1$d). Adzuna said: "%2$s". Check the credentials in your Adzuna developer account.', 'healthcare-jobs' ),
					$code,
					$api_message
				)
			);
		}

		if ( 429 === $code ) {
			return new WP_Error( 'healthcare_jobs_adzuna_rate_limited', __( 'Adzuna API rate limit reached. Try again later.', 'healthcare-jobs' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'healthcare_jobs_adzuna_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: Adzuna's response text */
					__( 'Adzuna API returned an unexpected error (HTTP %1$d). Adzuna said: "%2$s". If the configured country is not one Adzuna covers, this is the error you will see.', 'healthcare-jobs' ),
					$code,
					$api_message
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'healthcare_jobs_adzuna_bad_response', __( 'Adzuna API returned an unreadable response.', 'healthcare-jobs' ) );
		}

		return $data;
	}

	/**
	 * Pulls a short, human-readable explanation out of a non-2xx response.
	 * Only ever reads Adzuna's own response - never anything supplied by
	 * this plugin - so it is always safe to display.
	 *
	 * @param mixed  $data Decoded JSON body, or null/false if it wasn't JSON.
	 * @param string $body Raw response body.
	 * @return string
	 */
	private static function extract_api_message( $data, $body ) {
		if ( is_array( $data ) ) {
			foreach ( array( 'display', 'exception', 'message', 'error', 'detail' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					return $data[ $key ];
				}
			}
		}

		$body = trim( (string) $body );
		return '' === $body ? __( '(empty response body)', 'healthcare-jobs' ) : substr( $body, 0, 300 );
	}

	/**
	 * Builds the non-auth query params for a search request from
	 * plugin-level filter options.
	 *
	 * @param array $args {
	 *     @type string[] $job_titles   Job title OR list (sent as Adzuna's `what_or`).
	 *     @type int      $max_age_days Only jobs posted within this many days.
	 *     @type int      $limit        Page size (capped at MAX_PAGE_SIZE).
	 * }
	 * @return array
	 */
	public function build_search_params( array $args ) {
		$max_page_size = self::get_max_page_size();

		$params = array(
			'results_per_page' => isset( $args['limit'] ) ? max( 1, min( $max_page_size, (int) $args['limit'] ) ) : min( $max_page_size, 50 ),
			'sort_by'           => 'date',
		);

		if ( ! empty( $args['job_titles'] ) && is_array( $args['job_titles'] ) ) {
			$params['what_or'] = implode( ' ', array_map( 'strval', $args['job_titles'] ) );
		}

		if ( isset( $args['max_age_days'] ) ) {
			$params['max_days_old'] = max( 0, (int) $args['max_age_days'] );
		}

		/**
		 * Filters the outgoing Adzuna search query params before the
		 * request is sent.
		 *
		 * @param array $params Query params about to be sent (excluding app_id/app_key).
		 * @param array $args   Original plugin-level arguments.
		 */
		return apply_filters( 'healthcare_jobs_adzuna_search_params', $params, $args );
	}

	/**
	 * Runs the smallest valid request purely to validate the configured
	 * App ID/App Key, for the admin "Test Adzuna Connection" button.
	 *
	 * @return array{jobs_returned:int}|WP_Error
	 */
	public function test_connection() {
		$params = $this->build_search_params( array( 'limit' => 1, 'max_age_days' => 30 ) );

		$result = $this->search_jobs(
			array(
				'country_code' => Healthcare_Jobs_Settings::get( 'default_country', 'GB' ),
				'page'         => 1,
				'params'       => $params,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! array_key_exists( 'results', $result ) ) {
			return new WP_Error( 'healthcare_jobs_adzuna_bad_response', __( 'Connected, but the response did not look like an Adzuna jobs response.', 'healthcare-jobs' ) );
		}

		return array( 'jobs_returned' => count( $this->extract_jobs( $result ) ) );
	}

	/**
	 * Extracts the list of job records from a decoded search response.
	 *
	 * @param array $response Decoded API response.
	 * @return array
	 */
	public function extract_jobs( array $response ) {
		return isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
	}

	/**
	 * Reads the total-results count when present, for progress reporting.
	 *
	 * @param array $response Decoded API response.
	 * @return int|null
	 */
	public function extract_total_results( array $response ) {
		return isset( $response['count'] ) ? (int) $response['count'] : null;
	}
}
