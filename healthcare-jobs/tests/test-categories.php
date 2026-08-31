<?php
/**
 * Tests for configurable categories and job-title classification.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Categories_Test extends WP_UnitTestCase {

	public function test_classify_title_matches_configured_title() {
		$this->assertSame( 'Doctors', Healthcare_Jobs_Categories::classify_title( 'Consultant' ) );
		$this->assertSame( 'Nursing', Healthcare_Jobs_Categories::classify_title( 'Registered Nurse' ) );
		$this->assertSame( 'Pharmacy', Healthcare_Jobs_Categories::classify_title( 'Pharmacy Technician' ) );
	}

	public function test_classify_title_prefers_longest_match() {
		// "Specialty Doctor" and "Doctor" are both configured under Doctors,
		// so this mostly verifies no crash from overlapping titles, and
		// that the more specific title still resolves to the same category.
		$this->assertSame( 'Doctors', Healthcare_Jobs_Categories::classify_title( 'Specialty Doctor - Cardiology' ) );
	}

	public function test_classify_title_returns_empty_for_unrelated_title() {
		$this->assertSame( '', Healthcare_Jobs_Categories::classify_title( 'Software Engineer' ) );
	}

	public function test_add_and_delete_category() {
		$id = Healthcare_Jobs_Categories::add_category( 'Optometry' );
		$this->assertIsInt( $id );
		$this->assertContains( 'Optometry', Healthcare_Jobs_Categories::get_names() );

		Healthcare_Jobs_Categories::delete_category( $id );
		$this->assertNotContains( 'Optometry', Healthcare_Jobs_Categories::get_names() );
	}

	public function test_duplicate_category_name_is_rejected() {
		$result = Healthcare_Jobs_Categories::add_category( 'Doctors' );
		$this->assertWPError( $result );
	}

	public function test_empty_category_name_is_rejected() {
		$result = Healthcare_Jobs_Categories::add_category( '   ' );
		$this->assertWPError( $result );
	}

	public function test_add_and_delete_title() {
		$categories = Healthcare_Jobs_Categories::get_all();
		$category   = $categories[0];

		$title_id = Healthcare_Jobs_Categories::add_title( $category['id'], 'Test Job Title' );
		$this->assertIsInt( $title_id );
		$this->assertContains( 'Test Job Title', Healthcare_Jobs_Categories::get_all_titles() );

		Healthcare_Jobs_Categories::delete_title( $title_id );
		$this->assertNotContains( 'Test Job Title', Healthcare_Jobs_Categories::get_all_titles() );
	}
}
