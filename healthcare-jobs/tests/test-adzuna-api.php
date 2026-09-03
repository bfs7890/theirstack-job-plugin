<?php
/**
 * Tests for the Adzuna API client: auth, error handling, and the
 * "Test Adzuna Connection" flow. All HTTP calls are intercepted via
 * pre_http_request so no real network access happens in tests.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Adzuna_API_Test extends WP_UnitTestCase {

	private $mock_response;

	public function setUp(): void {
		parent::setUp();
		Healthcare_Jobs_Settings::save( array( 'adzuna_app_id' => 'app-id-123', 'adzuna_app_key' => 'app-key-abc' ) );
		$this->mock_response = null;
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tearDown();
	}

	public function intercept( $preempt, $args, $url ) {
		if ( is_callable( $this->mock_response ) ) {
			return call_user_func( $this->mock_response, $args, $url );
		}
		return $this->mock_response;
	}

	private function json_response_helper( array $body, $code = 200 ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $body ),
		);
	}

	public function test_missing_credentials_returns_error_without_http_call() {
		Healthcare_Jobs_Settings::save( array( 'adzuna_app_id' => '', 'clear_adzuna_app_key' => '1' ) );
		if ( defined( 'HEALTHCARE_JOBS_ADZUNA_APP_ID' ) || defined( 'HEALTHCARE_JOBS_ADZUNA_APP_KEY' ) ) {
			$this->markTestSkipped( 'Adzuna credential constant already defined in this process.' );
		}

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_adzuna_no_credentials', $result->get_error_code() );
	}

	public function test_credentials_are_sent_as_query_params_and_never_logged_as_error() {
		$this->mock_response = function ( $args, $url ) {
			$this->assertStringContainsString( 'app_id=app-id-123', $url );
			$this->assertStringContainsString( 'app_key=app-key-abc', $url );
			$this->assertStringContainsString( '/jobs/gb/search/1', $url );
			return $this->json_response_helper( array( 'results' => array(), 'count' => 0 ) );
		};

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		$this->assertIsArray( $result );
	}

	public function test_401_response_is_treated_as_auth_failure() {
		$this->mock_response = $this->json_response_helper( array( 'display' => 'invalid app_id or app_key' ), 401 );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_adzuna_auth_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'app-key-abc', $result->get_error_message() );
	}

	public function test_403_response_is_also_treated_as_auth_failure() {
		$this->mock_response = $this->json_response_helper( array( 'display' => 'forbidden' ), 403 );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_adzuna_auth_failed', $result->get_error_code() );
	}

	public function test_429_response_is_rate_limited_error() {
		$this->mock_response = array( 'response' => array( 'code' => 429 ), 'body' => '{}' );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_adzuna_rate_limited', $result->get_error_code() );
	}

	public function test_network_failure_is_propagated_as_wp_error() {
		$this->mock_response = new WP_Error( 'http_request_failed', 'Connection timed out' );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		$this->assertWPError( $result );
	}

	public function test_invalid_json_response_is_handled_gracefully() {
		$this->mock_response = array( 'response' => array( 'code' => 200 ), 'body' => 'this is not json' );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_adzuna_bad_response', $result->get_error_code() );
	}

	public function test_test_connection_success() {
		$this->mock_response = $this->json_response_helper( array( 'results' => array( array( 'id' => 'x' ) ), 'count' => 1 ) );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->test_connection();

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['jobs_returned'] );
	}

	public function test_test_connection_failure_returns_wp_error() {
		$this->mock_response = $this->json_response_helper( array( 'display' => 'invalid app_id or app_key' ), 401 );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->test_connection();

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_adzuna_auth_failed', $result->get_error_code() );
	}

	public function test_page_size_is_capped_at_maximum() {
		$api    = new Healthcare_Jobs_Adzuna_API();
		$params = $api->build_search_params( array( 'limit' => 10000 ) );

		$this->assertSame( Healthcare_Jobs_Adzuna_API::MAX_PAGE_SIZE, $params['results_per_page'] );
	}

	public function test_job_titles_are_sent_as_what_or() {
		$api    = new Healthcare_Jobs_Adzuna_API();
		$params = $api->build_search_params( array( 'job_titles' => array( 'Registered Nurse', 'Pharmacist' ) ) );

		$this->assertSame( 'Registered Nurse Pharmacist', $params['what_or'] );
	}
}
