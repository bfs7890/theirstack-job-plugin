<?php
/**
 * Tests for the TheirStack API client: auth, error handling, and the
 * "Test API Connection" flow. All HTTP calls are intercepted via
 * pre_http_request so no real network access happens in tests.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_TheirStack_API_Test extends WP_UnitTestCase {

	private $mock_response;

	public function setUp(): void {
		parent::setUp();
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-test-key' ) );
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

	public function test_missing_api_key_returns_error_without_http_call() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => '' ) );
		// Remove constant override if a previous test defined it (constants
		// can't be undefined, so only run this assertion when none is set).
		if ( defined( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY' ) ) {
			$this->markTestSkipped( 'API key constant already defined in this process.' );
		}

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->search_jobs( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_no_api_key', $result->get_error_code() );
	}

	public function test_authorization_header_is_sent_and_never_logged_as_error() {
		$this->mock_response = function ( $args ) {
			$this->assertSame( 'Bearer sk-test-key', $args['headers']['Authorization'] );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
			);
		};

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->search_jobs( array( 'limit' => 1 ) );

		$this->assertIsArray( $result );
	}

	public function test_401_response_is_treated_as_auth_failure() {
		$this->mock_response = array(
			'response' => array( 'code' => 401 ),
			'body'     => wp_json_encode( array( 'message' => 'Unauthorized' ) ),
		);

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->search_jobs( array( 'limit' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_auth_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'sk-test-key', $result->get_error_message() );
	}

	public function test_429_response_is_rate_limited_error() {
		$this->mock_response = array(
			'response' => array( 'code' => 429 ),
			'body'     => '{}',
		);

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->search_jobs( array( 'limit' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_rate_limited', $result->get_error_code() );
	}

	public function test_network_failure_is_propagated_as_wp_error() {
		$this->mock_response = new WP_Error( 'http_request_failed', 'Connection timed out' );

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->search_jobs( array( 'limit' => 1 ) );

		$this->assertWPError( $result );
	}

	public function test_invalid_json_response_is_handled_gracefully() {
		$this->mock_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'this is not json',
		);

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->search_jobs( array( 'limit' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'healthcare_jobs_bad_response', $result->get_error_code() );
	}

	public function test_test_connection_success() {
		$this->mock_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array(), 'metadata' => array( 'total_results' => 0 ) ) ),
		);

		$api = new Healthcare_Jobs_TheirStack_API();
		$this->assertTrue( $api->test_connection() );
	}

	public function test_test_connection_failure_returns_wp_error() {
		$this->mock_response = array(
			'response' => array( 'code' => 403 ),
			'body'     => '{}',
		);

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->test_connection();

		$this->assertWPError( $result );
	}

	public function test_build_search_params_includes_required_freshness_filter() {
		$api    = new Healthcare_Jobs_TheirStack_API();
		$params = $api->build_search_params( array( 'country_code' => 'GB', 'max_age_days' => 30 ) );

		$this->assertArrayHasKey( 'posted_at_max_age_days', $params );
		$this->assertSame( array( 'GB' ), $params['job_country_code_or'] );
	}

	public function test_page_size_is_capped_at_maximum() {
		$api    = new Healthcare_Jobs_TheirStack_API();
		$params = $api->build_search_params( array( 'limit' => 10000 ) );

		$this->assertSame( Healthcare_Jobs_TheirStack_API::MAX_PAGE_SIZE, $params['limit'] );
	}
}
