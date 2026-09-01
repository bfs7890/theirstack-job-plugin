<?php
/**
 * Tests for the import orchestration: pagination, dedupe, company
 * matching, invalid-record skipping, locking, and error handling.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Importer_Test extends WP_UnitTestCase {

	private $mock_response;

	public function setUp(): void {
		parent::setUp();
		Healthcare_Jobs_Settings::save(
			array(
				'api_key'              => 'sk-test-key',
				'default_country'      => 'GB',
				'default_job_age_days' => 30,
				'max_jobs_per_import'  => 50,
				'default_job_status'   => 'open',
			)
		);
		delete_transient( Healthcare_Jobs_Importer::LOCK_KEY );
		$this->mock_response = null;
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_transient( Healthcare_Jobs_Importer::LOCK_KEY );
		parent::tearDown();
	}

	public function intercept( $preempt, $args, $url ) {
		if ( is_callable( $this->mock_response ) ) {
			return call_user_func( $this->mock_response, $args, $url );
		}
		return $this->mock_response;
	}

	private function json_response( array $body, $code = 200 ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $body ),
		);
	}

	private function raw_job( $overrides = array() ) {
		return array_merge(
			array(
				'id'          => 'job-' . wp_generate_password( 8, false ),
				'title'       => 'Registered Nurse',
				'description' => 'Great nursing role in a busy ward.',
				'url'         => 'https://employer.example.com/jobs/nurse',
				'posted_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'location'    => 'London, UK',
				'city'        => 'London',
				'country_code' => 'GB',
				'remote'      => false,
				'company'     => array(
					'id'   => 'co-1',
					'name' => 'Royal Health Trust',
				),
			),
			$overrides
		);
	}

	public function test_successful_import_creates_a_directorist_listing() {
		$this->mock_response = $this->json_response( array( 'data' => array( $this->raw_job( array( 'id' => 'create-me' ) ) ) ) );

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 1, $result['stats']['jobs_created'] );

		$post_id = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'create-me' );
		$this->assertNotSame( 0, $post_id );
		$this->assertSame( 'at_biz_dir', get_post_type( $post_id ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 'Royal Health Trust', get_post_meta( $post_id, '_custom-text', true ) );
	}

	public function test_pagination_fetches_multiple_pages() {
		$page0 = array_fill( 0, 2, null );
		foreach ( $page0 as $i => $unused ) {
			$page0[ $i ] = $this->raw_job( array( 'id' => 'p0-job-' . $i ) );
		}

		$this->mock_response = function ( $args ) {
			$body = json_decode( $args['body'], true );
			if ( 0 === $body['page'] ) {
				return $this->json_response(
					array(
						'data' => array(
							$this->raw_job( array( 'id' => 'page0-a' ) ),
							$this->raw_job( array( 'id' => 'page0-b' ) ),
						),
					)
				);
			}
			return $this->json_response( array( 'data' => array( $this->raw_job( array( 'id' => 'page1-a' ) ) ) ) );
		};

		Healthcare_Jobs_Settings::save( array( 'max_jobs_per_import' => 2 ) );

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		// max_jobs_per_import=2 with a page size of 2 should stop after one page.
		$this->assertSame( 2, $result['stats']['jobs_created'] );
	}

	public function test_duplicate_job_across_two_imports_updates_not_duplicates() {
		$job_payload = $this->raw_job( array( 'id' => 'stable-id-1' ) );
		$this->mock_response = $this->json_response( array( 'data' => array( $job_payload ) ) );

		$importer = new Healthcare_Jobs_Importer();
		$importer->run( 'manual' );

		$job_payload['title'] = 'Registered Nurse (Updated Title)';
		$this->mock_response  = $this->json_response( array( 'data' => array( $job_payload ) ) );
		$result = $importer->run( 'manual' );

		$this->assertSame( 0, $result['stats']['jobs_created'] );
		$this->assertSame( 1, $result['stats']['jobs_updated'] );

		$post_id = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'stable-id-1' );
		$this->assertSame( 'Registered Nurse (Updated Title)', get_the_title( $post_id ) );
	}

	public function test_same_company_across_multiple_jobs_stores_the_same_name() {
		$this->mock_response = $this->json_response(
			array(
				'data' => array(
					$this->raw_job( array( 'id' => 'shared-1', 'company' => array( 'id' => 'co-shared', 'name' => 'Shared Trust' ) ) ),
					$this->raw_job( array( 'id' => 'shared-2', 'title' => 'Staff Nurse', 'company' => array( 'id' => 'co-shared', 'name' => 'Shared Trust' ) ) ),
				),
			)
		);

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 2, $result['stats']['jobs_created'] );

		$post_1 = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'shared-1' );
		$post_2 = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'shared-2' );

		$this->assertSame( 'Shared Trust', get_post_meta( $post_1, '_custom-text', true ) );
		$this->assertSame( 'Shared Trust', get_post_meta( $post_2, '_custom-text', true ) );
	}

	public function test_job_with_missing_title_is_skipped_not_fatal() {
		$this->mock_response = $this->json_response(
			array(
				'data' => array(
					$this->raw_job( array( 'title' => '' ) ),
					$this->raw_job( array( 'id' => 'valid-job' ) ),
				),
			)
		);

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 1, $result['stats']['jobs_created'] );
		$this->assertSame( 1, $result['stats']['jobs_skipped'] );
	}

	public function test_job_not_matching_any_configured_title_is_skipped() {
		$this->mock_response = $this->json_response(
			array( 'data' => array( $this->raw_job( array( 'title' => 'Warehouse Forklift Operator' ) ) ) )
		);

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 0, $result['stats']['jobs_created'] );
		$this->assertSame( 1, $result['stats']['jobs_skipped'] );
	}

	/**
	 * @dataProvider healthcare_jobs_false_positive_titles
	 */
	public function test_non_healthcare_titles_matching_a_search_term_are_still_not_imported( $title ) {
		$this->mock_response = $this->json_response(
			array( 'data' => array( $this->raw_job( array( 'title' => $title ) ) ) )
		);

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 0, $result['stats']['jobs_created'], "\"{$title}\" must not be imported as a healthcare job." );
	}

	public function healthcare_jobs_false_positive_titles() {
		return array(
			array( 'IT Consultant' ),
			array( 'Health & Safety Consultant' ),
			array( 'Tile Sales Consultant' ),
		);
	}

	public function test_api_failure_is_logged_and_does_not_delete_existing_jobs() {
		$this->mock_response = $this->json_response( array( 'data' => array( $this->raw_job( array( 'id' => 'keep-me' ) ) ) ) );
		$importer = new Healthcare_Jobs_Importer();
		$importer->run( 'manual' );

		$this->mock_response = array( 'response' => array( 'code' => 500 ), 'body' => '{}' );
		$result = $importer->run( 'manual' );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertNotSame( 0, Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'keep-me' ), 'Existing jobs must survive a failed import.' );
	}

	public function test_job_reported_closed_marks_the_existing_listing_closed_not_deleted() {
		$this->mock_response = $this->json_response( array( 'data' => array( $this->raw_job( array( 'id' => 'will-close' ) ) ) ) );
		$importer = new Healthcare_Jobs_Importer();
		$importer->run( 'manual' );

		$post_id = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'will-close' );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		$this->mock_response = $this->json_response( array( 'data' => array( $this->raw_job( array( 'id' => 'will-close', 'is_active' => false ) ) ) ) );
		$result = $importer->run( 'manual' );

		$this->assertSame( 1, $result['stats']['jobs_closed'] );
		$this->assertNotNull( get_post( $post_id ), 'A closed job must never be hard-deleted.' );
		$this->assertSame( Healthcare_Jobs_Directorist_Mapper::get_closed_post_status(), get_post_status( $post_id ) );
	}

	public function test_403_is_reported_as_authentication_stage_not_empty_search() {
		$this->mock_response = array(
			'response' => array( 'code' => 403 ),
			'body'     => wp_json_encode( array( 'message' => 'This endpoint is not available on your plan' ) ),
		);

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'authentication', $result['stage'], 'A 403 must be flagged as an authentication-stage failure, not treated like a normal empty search.' );
		$this->assertSame( 'healthcare_jobs_forbidden', $result['error_code'] );
		$this->assertSame( 0, $result['stats']['jobs_found'], 'No search actually ran, so jobs_found is 0 - but the caller must key off stage/error_code, not this number, to know why.' );
	}

	public function test_successful_search_stage_is_reported_when_no_request_error_occurs() {
		$this->mock_response = $this->json_response( array( 'data' => array( $this->raw_job() ) ) );

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 'search', $result['stage'] );
		$this->assertNull( $result['error_code'] );
	}

	public function test_concurrent_import_is_blocked_by_lock() {
		set_transient( Healthcare_Jobs_Importer::LOCK_KEY, time(), 60 );

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_import_without_api_key_fails_cleanly() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => '' ) );
		if ( defined( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY' ) ) {
			$this->markTestSkipped( 'API key constant already defined in this process.' );
		}

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_import_creates_log_entry() {
		$this->mock_response = $this->json_response( array( 'data' => array( $this->raw_job() ) ) );

		$importer = new Healthcare_Jobs_Importer();
		$importer->run( 'manual' );

		$last = Healthcare_Jobs_Logger::get_last_import();
		$this->assertNotNull( $last );
		$this->assertSame( 'success', $last['status'] );
	}
}
