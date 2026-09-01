<?php
/**
 * Tests for configurable categories and job-title classification.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Categories_Test extends WP_UnitTestCase {

	public function test_add_and_delete_category() {
		$term = wp_insert_term( 'Optometry', Healthcare_Jobs_Categories::TAXONOMY, array( 'slug' => 'optometry-test' ) );
		$id   = Healthcare_Jobs_Categories::add_category( 'Optometry', $term['term_id'] );
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

	public function test_add_title_stores_ambiguous_context_and_exclusion_terms() {
		$categories = Healthcare_Jobs_Categories::get_all();
		$category   = $categories[0];

		$title_id = Healthcare_Jobs_Categories::add_title(
			$category['id'],
			'Test Ambiguous Title',
			true,
			array( 'Clinical' ),
			array( 'IT' )
		);

		$rules = Healthcare_Jobs_Categories::get_classification_rules();
		$rule  = null;
		foreach ( $rules as $candidate ) {
			if ( 'Test Ambiguous Title' === $candidate['title'] ) {
				$rule = $candidate;
			}
		}

		$this->assertNotNull( $rule );
		$this->assertTrue( $rule['is_ambiguous'] );
		$this->assertContains( 'Clinical', $rule['context_terms'] );
		$this->assertContains( 'IT', $rule['exclusion_terms'] );

		Healthcare_Jobs_Categories::delete_title( $title_id );
	}

	public function test_get_directorist_terms_returns_real_taxonomy_terms() {
		$terms = Healthcare_Jobs_Categories::get_directorist_terms();
		$this->assertNotEmpty( $terms );
		$this->assertInstanceOf( WP_Term::class, $terms[0] );
	}
}
