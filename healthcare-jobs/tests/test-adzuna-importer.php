<?php
/**
 * Tests for the Adzuna import orchestration: field mapping into the same
 * Directorist schema TheirStack uses, dedupe against TheirStack's own
 * external IDs, and the "no company website in Adzuna's response" case.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Adzuna_Importer_Test extends WP_UnitTestCase {

	private $mock_response;

	public function setUp(): void {
		parent::setUp();
		Healthcare_Jobs_Classifier::clear_cache();
		Healthcare_Jobs_Settings::save(
			array(
				'adzuna_app_id'        => 'app-id-123',
				'adzuna_app_key'       => 'app-key-abc',
				'default_country'      => 'GB',
				'default_job_age_days' => 30,
				'max_jobs_per_import'  => 50,
			)
		);
		delete_transient( Healthcare_Jobs_Adzuna_Importer::LOCK_KEY );
		$this->mock_response = null;
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_transient( Healthcare_Jobs_Adzuna_Importer::LOCK_KEY );
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
				'id'           => '1' . wp_generate_password( 8, false, false ),
				'title'        => 'Registered Nurse',
				'description'  => 'Great nursing role in a busy ward.',
				'redirect_url' => 'https://www.adzuna.co.uk/land/ad/12345',
				'created'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'company'      => array( 'display_name' => 'Outcomes First Group' ),
				'location'     => array(
					'display_name' => 'London, South East London',
					'area'         => array( 'UK', 'London', 'South East London' ),
				),
				'salary_min'    => 28000,
				'salary_max'    => 32000,
				'contract_time' => 'full_time',
			),
			$overrides
		);
	}

	public function test_successful_import_creates_a_directorist_listing_with_no_company_website() {
		$this->mock_response = $this->json_response( array( 'results' => array( $this->raw_job( array( 'id' => 'create-me' ) ) ), 'count' => 1 ) );

		$importer = new Healthcare_Jobs_Adzuna_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 1, $result['stats']['jobs_created'] );

		// Prefixed so it can never collide with a TheirStack external ID in
		// the shared postmeta dedupe lookup.
		$post_id = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'adzuna-create-me' );
		$this->assertNotSame( 0, $post_id );
		$this->assertSame( 'at_biz_dir', get_post_type( $post_id ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		// Same Directorist fields TheirStack jobs use - this is what makes
		// the Adzuna job render through the identical single-listing layout.
		$this->assertSame( 'Outcomes First Group', get_post_meta( $post_id, '_custom-text', true ) );
		$this->assertSame( '28000-32000', get_post_meta( $post_id, '_dirjob_salary', true ) );
		$this->assertSame( 'https://www.adzuna.co.uk/land/ad/12345', get_post_meta( $post_id, '_djobs-apply-now', true ) );
		$this->assertSame( 'Full-time', get_post_meta( $post_id, '_custom-select', true ) );

		// Adzuna's response has no company website field - _custom-url must
		// stay empty, which is what makes the Company Website button not
		// appear for this listing (see class-single-listing.php).
		$this->assertSame( '', get_post_meta( $post_id, '_custom-url', true ) );
	}

	public function test_adzuna_and_theirstack_external_ids_never_collide() {
		// Same raw numeric ID, but from two different sources - the
		// "adzuna-" prefix must keep these as two distinct listings. A
		// single URL-dispatching mock (rather than a second pre_http_request
		// filter) avoids fighting with the intercept() filter already
		// registered in setUp(), which does not itself check $preempt.
		$shared_id = 'shared-numeric-id';

		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-test-key' ) );

		$this->mock_response = function ( $args, $url ) use ( $shared_id ) {
			if ( false !== strpos( $url, 'api.theirstack.com' ) ) {
				return $this->json_response( array( 'data' => array( array(
					'id'          => $shared_id,
					'title'       => 'Registered Nurse',
					'description' => 'A nursing role.',
					'url'         => 'https://employer.example.com/jobs/nurse',
					'company'     => array( 'name' => 'Royal Health Trust' ),
				) ) ) );
			}
			return $this->json_response( array( 'results' => array( $this->raw_job( array( 'id' => $shared_id ) ) ), 'count' => 1 ) );
		};

		$theirstack_importer = new Healthcare_Jobs_Importer();
		$theirstack_importer->run( 'manual' );

		$adzuna_importer = new Healthcare_Jobs_Adzuna_Importer();
		$adzuna_importer->run( 'manual' );

		$theirstack_post_id = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( $shared_id );
		$adzuna_post_id     = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'adzuna-' . $shared_id );

		$this->assertNotSame( 0, $theirstack_post_id );
		$this->assertNotSame( 0, $adzuna_post_id );
		$this->assertNotSame( $theirstack_post_id, $adzuna_post_id );
	}

	public function test_missing_credentials_fails_before_any_search() {
		Healthcare_Jobs_Settings::save( array( 'adzuna_app_id' => '', 'clear_adzuna_app_key' => '1' ) );
		if ( defined( 'HEALTHCARE_JOBS_ADZUNA_APP_ID' ) || defined( 'HEALTHCARE_JOBS_ADZUNA_APP_KEY' ) ) {
			$this->markTestSkipped( 'Adzuna credential constant already defined in this process.' );
		}

		$importer = new Healthcare_Jobs_Adzuna_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 'authentication', $result['stage'] );
		$this->assertSame( 0, $result['stats']['jobs_found'] );
	}

	public function test_a_job_that_does_not_match_a_configured_category_is_skipped() {
		$this->mock_response = $this->json_response(
			array( 'results' => array( $this->raw_job( array( 'id' => 'skip-me', 'title' => 'Freelance Graphic Designer', 'description' => 'Design brochures.' ) ) ), 'count' => 1 )
		);

		$importer = new Healthcare_Jobs_Adzuna_Importer();
		$result   = $importer->run( 'manual' );

		$this->assertSame( 1, $result['stats']['jobs_skipped'] );
		$this->assertSame( 0, Healthcare_Jobs_Directorist_Sync::find_existing_post_id( 'adzuna-skip-me' ) );
	}
}
