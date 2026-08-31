<?php
/**
 * Tests for the public search/filter query builder.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Search_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$now   = current_time( 'mysql', true );

		$jobs = array(
			array(
				'external_job_id' => 'search-1',
				'slug'             => 'consultant-cardiologist-london',
				'title'            => 'Consultant Cardiologist',
				'company_name'     => 'Royal Heart Hospital',
				'location'         => 'London',
				'city'             => 'London',
				'category'         => 'Doctors',
				'employment_type'  => 'Full-time',
				'remote_type'      => 'onsite',
				'salary_min'       => 90000,
				'salary_max'       => 120000,
				'salary_currency'  => 'GBP',
				'status'           => 'active',
				'posted_at'        => current_time( 'mysql', true ),
			),
			array(
				'external_job_id' => 'search-2',
				'slug'             => 'staff-nurse-manchester',
				'title'            => 'Staff Nurse',
				'company_name'     => 'Northern Care Trust',
				'location'         => 'Manchester',
				'city'             => 'Manchester',
				'category'         => 'Nursing',
				'employment_type'  => 'Part-time',
				'remote_type'      => 'onsite',
				'salary_min'       => 28000,
				'salary_max'       => 32000,
				'salary_currency'  => 'GBP',
				'status'           => 'active',
				'posted_at'        => gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ),
			),
			array(
				'external_job_id' => 'search-3',
				'slug'             => 'closed-role',
				'title'            => 'Closed Role Should Not Appear',
				'company_name'     => 'Old Employer',
				'location'         => 'Leeds',
				'category'         => 'Doctors',
				'status'           => 'closed',
				'posted_at'        => current_time( 'mysql', true ),
			),
		);

		foreach ( $jobs as $job ) {
			$job['first_seen_at']   = $now;
			$job['last_updated_at'] = $now;
			$job['created_at']      = $now;
			$job['updated_at']      = $now;
			$wpdb->insert( $table, $job );
		}
	}

	public function test_only_active_jobs_are_returned() {
		$result = Healthcare_Jobs_Search::query( array() );
		$titles = wp_list_pluck( $result['items'], 'title' );

		$this->assertNotContains( 'Closed Role Should Not Appear', $titles );
	}

	public function test_keyword_search_finds_matching_title() {
		$result = Healthcare_Jobs_Search::query( array( 'keyword' => 'Cardiologist' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Consultant Cardiologist', $result['items'][0]['title'] );
	}

	public function test_location_filter() {
		$result = Healthcare_Jobs_Search::query( array( 'location' => 'Manchester' ) );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_category_filter() {
		$result = Healthcare_Jobs_Search::query( array( 'category' => 'Nursing' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Staff Nurse', $result['items'][0]['title'] );
	}

	public function test_salary_min_filter_excludes_lower_paying_jobs() {
		$result = Healthcare_Jobs_Search::query( array( 'salary_min' => 80000 ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Consultant Cardiologist', $result['items'][0]['title'] );
	}

	public function test_date_posted_filter() {
		$result = Healthcare_Jobs_Search::query( array( 'date_posted' => '7d' ) );
		$titles = wp_list_pluck( $result['items'], 'title' );

		$this->assertContains( 'Consultant Cardiologist', $titles );
		$this->assertNotContains( 'Staff Nurse', $titles );
	}

	public function test_pagination_limits_results_per_page() {
		$result = Healthcare_Jobs_Search::query( array( 'per_page' => 1, 'paged' => 1 ) );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 2, $result['pages'] );
	}

	public function test_sql_injection_attempt_is_neutralised() {
		$result = Healthcare_Jobs_Search::query( array( 'keyword' => "' OR '1'='1" ) );
		$this->assertIsArray( $result['items'] );
		// Should behave as a normal (non-matching) search, not error or dump all rows.
		$this->assertSame( 0, $result['total'] );
	}
}
