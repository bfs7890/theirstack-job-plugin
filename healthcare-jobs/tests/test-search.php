<?php
/**
 * Tests for the public search/filter query builder against real Directorist
 * (`at_biz_dir`) listings - the frontend never queries TheirStack directly
 * or a separate job table, only Directorist itself.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Search_Test extends WP_UnitTestCase {

	private function sync_job( array $overrides = array() ) {
		$job = array_merge(
			array(
				'external_job_id' => 'search-' . wp_generate_password( 8, false ),
				'source'          => 'theirstack',
				'source_url'      => 'https://employer.example.com/apply',
				'title'           => 'Registered Nurse',
				'description'     => 'A nursing role in a busy ward.',
				'company_name'    => 'Royal Heart Hospital',
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
				'salary_min'      => 90000,
				'salary_max'      => 120000,
				'salary_currency' => 'GBP',
				'posted_at'       => current_time( 'mysql', true ),
				'closing_date'    => null,
				'is_closed'       => false,
				'raw_data'        => array(),
			),
			$overrides
		);

		$category_slug = $overrides['category_slug'] ?? 'nurses';
		unset( $job['category_slug'] );
		$term = get_term_by( 'slug', $category_slug, Healthcare_Jobs_Categories::TAXONOMY );

		$result = Healthcare_Jobs_Directorist_Sync::sync( $job, $term->term_id );
		$this->assertNull( $result['error'], 'Fixture sync failed: ' . (string) $result['error'] );
		return $result['post_id'];
	}

	public function setUp(): void {
		parent::setUp();

		$this->sync_job(
			array(
				'title'         => 'Consultant Cardiologist',
				'company_name'  => 'Royal Heart Hospital',
				'city'          => 'London',
				'category_slug' => 'consultants',
				'salary_min'    => 90000,
				'salary_max'    => 120000,
				'description'   => 'Leading role as a Cardiologist.',
			)
		);
		$this->sync_job(
			array(
				'title'         => 'Staff Nurse',
				'company_name'  => 'Northern Care Trust',
				'city'          => 'Manchester',
				'category_slug' => 'nurses',
				'salary_min'    => 28000,
				'salary_max'    => 32000,
				'posted_at'     => gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ),
			)
		);
		$this->sync_job(
			array(
				'title'         => 'Closed Role Should Not Appear',
				'company_name'  => 'Old Employer',
				'city'          => 'Leeds',
				'category_slug' => 'doctors',
				'is_closed'     => true,
			)
		);
	}

	public function test_only_published_jobs_are_returned() {
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
		$term   = get_term_by( 'slug', 'nurses', Healthcare_Jobs_Categories::TAXONOMY );
		$result = Healthcare_Jobs_Search::query( array( 'category' => $term->term_id ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Staff Nurse', $result['items'][0]['title'] );
	}

	public function test_unknown_category_returns_no_results_not_the_full_set() {
		$result = Healthcare_Jobs_Search::query( array( 'category' => 'not-a-real-category' ) );
		$this->assertSame( 0, $result['total'] );
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
		$this->assertSame( 0, $result['total'] );
	}

	public function test_card_data_includes_permalink_and_never_exposes_api_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-should-never-appear-in-search' ) );

		$result = Healthcare_Jobs_Search::query( array( 'keyword' => 'Cardiologist' ) );
		$card   = $result['items'][0];

		$this->assertNotEmpty( $card['permalink'] );
		$this->assertStringNotContainsString( 'sk-should-never-appear-in-search', wp_json_encode( $card ) );
	}
}
