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
	 * Maximum jobs per page TheirStack will return in a single request.
	 *
	 * TheirStack's general API docs cite up to 500, but the current free
	 * plan caps at 25 per page regardless - requesting more than the
	 * plan allows risks a 422/403 rather than a smaller page, so this
	 * stays at the safe, confirmed-working value. The importer already
	 * loops multiple pages to reach a larger configured maximum, so this
	 * only affects how many requests that takes, not how many jobs can be
	 * imported in total. Raise via the `healthcare_jobs_theirstack_max_page_size`
	 * filter if the account is upgraded to a plan with a higher per-page limit.
	 *
	 * @var int
	 */
	const MAX_PAGE_SIZE = 25;

	/**
	 * Returns the effective maximum page size (MAX_PAGE_SIZE, unless
	 * overridden via the `healthcare_jobs_theirstack_max_page_size` filter).
	 * Callers that need to plan pagination (e.g. the importer) should use
	 * this rather than the raw constant so a filtered override is honoured
	 * consistently everywhere.
	 *
	 * @return int
	 */
	public static function get_max_page_size() {
		return (int) apply_filters( 'healthcare_jobs_theirstack_max_page_size', self::MAX_PAGE_SIZE );
	}

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
		$key_warnings = Healthcare_Jobs_Settings::get_api_key_warnings();
		Healthcare_Jobs_Logger::debug(
			sprintf(
				'TheirStack request -> POST %s | key_present=%s key_length=%d key_first4=%s key_last4=%s authorization=%s warnings=%s',
				self::API_BASE . '/jobs/search',
				empty( $api_key ) ? 'NO' : 'YES',
				strlen( (string) $api_key ),
				empty( $api_key ) ? 'n/a' : substr( $api_key, 0, 4 ),
				empty( $api_key ) ? 'n/a' : substr( $api_key, -4 ),
				empty( $api_key ) ? 'none' : ( 'Bearer ' . str_repeat( '*', max( 0, strlen( $api_key ) - 4 ) ) . substr( $api_key, -4 ) ),
				empty( $key_warnings ) ? 'none' : implode( '; ', $key_warnings )
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

		// TheirStack's own explanation (a "message"/"detail"/"error" field,
		// or the raw body) is the single most useful diagnostic here - it
		// never contains our API key, only TheirStack's response, so it is
		// always safe to surface directly in the admin UI.
		$api_message = self::extract_api_message( $data, $body );
		$key_summary = sprintf(
			'[key present: %1$s, length: %2$d, ends with: %3$s]',
			empty( $api_key ) ? 'no' : 'yes',
			strlen( (string) $api_key ),
			empty( $api_key ) ? 'n/a' : substr( $api_key, -4 )
		);

		if ( 401 === $code ) {
			return new WP_Error(
				'healthcare_jobs_auth_failed',
				sprintf(
					/* translators: 1: TheirStack's response text, 2: masked key summary */
					__( 'TheirStack rejected the request: the API key is missing or invalid (HTTP 401). TheirStack said: "%1$s" %2$s. Regenerate or check the key in TheirStack -> Settings -> API Keys.', 'healthcare-jobs' ),
					$api_message,
					$key_summary
				)
			);
		}

		if ( 403 === $code ) {
			return new WP_Error(
				'healthcare_jobs_forbidden',
				sprintf(
					/* translators: 1: TheirStack's response text, 2: masked key summary */
					__( 'TheirStack accepted the key but forbade this request (HTTP 403) - this usually means the account/plan does not have access to this endpoint, or is out of credits, rather than a malformed key. TheirStack said: "%1$s" %2$s. Check your plan and API access at TheirStack -> Settings -> API Keys.', 'healthcare-jobs' ),
					$api_message,
					$key_summary
				)
			);
		}

		if ( 402 === $code ) {
			return new WP_Error(
				'healthcare_jobs_payment_required',
				sprintf(
					/* translators: %s: TheirStack's response text */
					__( 'TheirStack reports insufficient credits or a billing issue (HTTP 402). TheirStack said: "%s". Check credits/billing at TheirStack -> Settings.', 'healthcare-jobs' ),
					$api_message
				)
			);
		}

		if ( 422 === $code ) {
			return new WP_Error(
				'healthcare_jobs_invalid_request',
				sprintf(
					/* translators: %s: TheirStack's response text */
					__( 'TheirStack rejected the request body as invalid (HTTP 422). TheirStack said: "%s".', 'healthcare-jobs' ),
					$api_message
				)
			);
		}

		if ( 429 === $code ) {
			return new WP_Error( 'healthcare_jobs_rate_limited', __( 'TheirStack API rate limit reached. Try again later.', 'healthcare-jobs' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'healthcare_jobs_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: TheirStack's response text */
					__( 'TheirStack API returned an unexpected error (HTTP %1$d). TheirStack said: "%2$s".', 'healthcare-jobs' ),
					$code,
					$api_message
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'healthcare_jobs_bad_response', __( 'TheirStack API returned an unreadable response.', 'healthcare-jobs' ) );
		}

		return $data;
	}

	/**
	 * Pulls a short, human-readable explanation out of a non-2xx response.
	 * Only ever reads TheirStack's own response - never anything supplied
	 * by this plugin - so it is always safe to display.
	 *
	 * @param mixed  $data Decoded JSON body, or null/false if it wasn't JSON.
	 * @param string $body Raw response body.
	 * @return string
	 */
	private static function extract_api_message( $data, $body ) {
		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'detail', 'error', 'title' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					return $data[ $key ];
				}
			}
			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$first = reset( $data['errors'] );
				if ( is_string( $first ) ) {
					return $first;
				}
				if ( is_array( $first ) && ! empty( $first['message'] ) ) {
					return (string) $first['message'];
				}
			}
		}

		$body = trim( (string) $body );
		return '' === $body ? __( '(empty response body)', 'healthcare-jobs' ) : substr( $body, 0, 300 );
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
		/**
		 * Filters the maximum jobs per page sent to TheirStack. Defaults to
		 * MAX_PAGE_SIZE (the confirmed safe limit for the current plan) -
		 * raise this only if the account has been upgraded to a plan that
		 * genuinely allows a larger page size.
		 *
		 * @param int $max_page_size Default maximum page size.
		 */
		$max_page_size = (int) apply_filters( 'healthcare_jobs_theirstack_max_page_size', self::MAX_PAGE_SIZE );

		$params = array(
			'page'  => isset( $args['page'] ) ? max( 0, (int) $args['page'] ) : 0,
			'limit' => isset( $args['limit'] ) ? max( 1, min( $max_page_size, (int) $args['limit'] ) ) : min( $max_page_size, 100 ),
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
	 * Runs the smallest valid request purely to validate the configured API
	 * key and authorization, for the admin "Test API Connection" button.
	 * Deliberately no country/title filter - just the one freshness filter
	 * TheirStack requires - so this tests authentication only, not the
	 * healthcare search itself.
	 *
	 * @return array{jobs_returned:int}|WP_Error
	 */
	public function test_connection() {
		$params = $this->build_search_params(
			array(
				'max_age_days' => 30,
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

		return array( 'jobs_returned' => count( $this->extract_jobs( $result ) ) );
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
