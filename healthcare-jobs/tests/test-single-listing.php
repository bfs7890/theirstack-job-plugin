<?php
/**
 * Tests for Healthcare_Jobs_Single_Listing's "Company Website" button
 * asset scoping: enqueued only on a Jobs listing's single page, and only
 * when that specific job actually has a company website URL set.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Single_Listing_Test extends WP_UnitTestCase {

	private function create_job_listing( $overrides = array() ) {
		$job = array(
			'external_job_id'  => 'ts-' . wp_generate_password( 8, false ),
			'source'           => 'theirstack',
			'source_url'       => 'https://employer.example.com/apply',
			'title'            => 'Registered Nurse',
			'description'      => 'A nursing role.',
			'company_name'     => 'Acme Health',
			'company_website'  => 'https://acmehealth.example.com',
			'company_logo_url' => '',
			'location'         => 'London, UK',
			'city'             => 'London',
			'region'           => '',
			'postcode'         => '',
			'country'          => 'United Kingdom',
			'country_code'     => 'GB',
			'employment_type'  => 'full-time',
			'remote_type'      => 'onsite',
			'salary_min'       => 30000,
			'salary_max'       => 35000,
			'salary_currency'  => 'GBP',
			'posted_at'        => current_time( 'mysql', true ),
			'closing_date'     => null,
			'is_closed'        => false,
			'raw_data'         => array(),
		);
		$job = array_merge( $job, $overrides );

		$term = get_term_by( 'slug', 'nurses', Healthcare_Jobs_Categories::TAXONOMY );

		$result = Healthcare_Jobs_Directorist_Sync::sync( $job, $term->term_id );
		$this->assertNull( $result['error'], 'Fixture sync failed: ' . (string) $result['error'] );

		return $result['post_id'];
	}

	public function test_enqueues_button_assets_on_a_job_listing_with_a_website() {
		$post_id = $this->create_job_listing();

		$this->go_to( get_permalink( $post_id ) );
		Healthcare_Jobs_Single_Listing::maybe_enqueue_assets();

		$this->assertTrue( wp_script_is( 'healthcare-jobs-single-listing', 'enqueued' ) );
		$this->assertSame( 'https://acmehealth.example.com', get_post_meta( $post_id, '_custom-url', true ) );
	}

	public function test_skips_when_company_website_is_empty() {
		$post_id = $this->create_job_listing( array( 'company_website' => '' ) );

		$this->go_to( get_permalink( $post_id ) );
		Healthcare_Jobs_Single_Listing::maybe_enqueue_assets();

		$this->assertFalse( wp_script_is( 'healthcare-jobs-single-listing', 'enqueued' ) );
	}

	public function test_skips_listings_outside_the_jobs_listing_type() {
		$post_id = $this->create_job_listing();
		// Simulate a non-Jobs listing on the same shared Directorist
		// install - the button must never appear there.
		wp_set_object_terms( $post_id, array(), Healthcare_Jobs_Search::LISTING_TYPE_TAXONOMY );

		$this->go_to( get_permalink( $post_id ) );
		Healthcare_Jobs_Single_Listing::maybe_enqueue_assets();

		$this->assertFalse( wp_script_is( 'healthcare-jobs-single-listing', 'enqueued' ) );
	}

	public function test_skips_non_listing_pages() {
		$this->create_job_listing();

		$this->go_to( home_url( '/' ) );
		Healthcare_Jobs_Single_Listing::maybe_enqueue_assets();

		$this->assertFalse( wp_script_is( 'healthcare-jobs-single-listing', 'enqueued' ) );
	}
}
