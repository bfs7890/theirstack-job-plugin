<?php
/**
 * Tests for the admin-facing Healthcare_Jobs_Jobs helpers: dashboard stats,
 * the admin Jobs list query, and status/delete row actions - all reading
 * and writing real Directorist (`at_biz_dir`) listings, since Directorist
 * is the authoritative store for listing content.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Jobs_Test extends WP_UnitTestCase {

	private function create_job_listing( $overrides = array() ) {
		$job = array(
			'external_job_id' => 'ts-' . wp_generate_password( 8, false ),
			'source'          => 'theirstack',
			'source_url'      => 'https://employer.example.com/apply',
			'title'           => 'Registered Nurse',
			'description'     => 'A nursing role.',
			'company_name'    => 'Acme Health',
			'company_website' => '',
			'company_logo_url' => '',
			'location'        => 'London, UK',
			'city'            => 'London',
			'region'          => '',
			'postcode'        => '',
			'country'         => 'United Kingdom',
			'country_code'    => 'GB',
			'employment_type' => 'full-time',
			'remote_type'     => 'onsite',
			'salary_min'      => 30000,
			'salary_max'      => 35000,
			'salary_currency' => 'GBP',
			'posted_at'       => current_time( 'mysql', true ),
			'closing_date'    => null,
			'is_closed'       => false,
			'raw_data'        => array(),
		);
		$job = array_merge( $job, $overrides );

		$term = get_term_by( 'slug', 'nurses', Healthcare_Jobs_Categories::TAXONOMY );

		$result = Healthcare_Jobs_Directorist_Sync::sync( $job, $term->term_id );
		$this->assertNull( $result['error'], 'Fixture sync failed: ' . (string) $result['error'] );

		return $result['post_id'];
	}

	public function test_get_stats_counts_published_and_closed_listings() {
		$this->create_job_listing();
		$this->create_job_listing( array( 'is_closed' => true ) );

		$stats = Healthcare_Jobs_Jobs::get_stats();

		$this->assertSame( 1, $stats['active_jobs'] );
		$this->assertSame( 1, $stats['expired_jobs'] );
		$this->assertSame( 2, $stats['total_jobs'] );
	}

	public function test_get_stats_counts_distinct_companies() {
		$this->create_job_listing( array( 'company_name' => 'Shared Trust' ) );
		$this->create_job_listing( array( 'company_name' => 'Shared Trust' ) );
		$this->create_job_listing( array( 'company_name' => 'Other Trust' ) );

		$stats = Healthcare_Jobs_Jobs::get_stats();
		$this->assertSame( 2, $stats['company_count'] );
	}

	public function test_admin_query_finds_by_title_and_company() {
		$this->create_job_listing( array( 'title' => 'Staff Nurse', 'company_name' => 'Findable Trust' ) );
		$this->create_job_listing( array( 'title' => 'Pharmacist', 'company_name' => 'Other Trust' ) );

		$result = Healthcare_Jobs_Jobs::admin_query( array( 'search' => 'Findable' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Staff Nurse', $result['items'][0]['title'] );
	}

	public function test_admin_query_filters_by_active_status() {
		$this->create_job_listing( array( 'title' => 'Active One' ) );
		$this->create_job_listing( array( 'title' => 'Closed One', 'is_closed' => true ) );

		$active = Healthcare_Jobs_Jobs::admin_query( array( 'status' => 'active' ) );
		$titles = wp_list_pluck( $active['items'], 'title' );

		$this->assertContains( 'Active One', $titles );
		$this->assertNotContains( 'Closed One', $titles );
	}

	public function test_admin_row_exposes_permalink_and_external_source() {
		$post_id = $this->create_job_listing( array( 'title' => 'Row Test', 'source_url' => 'https://employer.example.com/apply/row-test' ) );

		$result = Healthcare_Jobs_Jobs::admin_query( array( 'search' => 'Row Test' ) );
		$row    = $result['items'][0];

		$this->assertSame( $post_id, $row['id'] );
		$this->assertNotEmpty( $row['permalink'] );
		$this->assertSame( 'https://employer.example.com/apply/row-test', $row['source_url'] );
	}

	public function test_set_status_deactivate_and_activate() {
		$post_id = $this->create_job_listing();

		Healthcare_Jobs_Jobs::set_status( $post_id, Healthcare_Jobs_Directorist_Mapper::get_closed_post_status() );
		$this->assertSame( Healthcare_Jobs_Directorist_Mapper::get_closed_post_status(), get_post_status( $post_id ) );

		Healthcare_Jobs_Jobs::set_status( $post_id, Healthcare_Jobs_Jobs::STATUS_ACTIVE );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	public function test_set_status_rejects_unknown_status() {
		$post_id = $this->create_job_listing();

		Healthcare_Jobs_Jobs::set_status( $post_id, 'not-a-real-status' );
		$this->assertSame( 'publish', get_post_status( $post_id ), 'An unrecognised status must be ignored, not applied.' );
	}

	public function test_delete_removes_the_listing() {
		$post_id = $this->create_job_listing();

		Healthcare_Jobs_Jobs::delete( $post_id );

		$this->assertNull( get_post( $post_id ) );
	}
}
