<?php
/**
 * Tests for schema creation and default seeding.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Database_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Healthcare_Jobs_Classifier::clear_cache();
	}

	public function test_tables_are_created() {
		global $wpdb;

		$tables = array(
			Healthcare_Jobs_Database::jobs_table(),
			Healthcare_Jobs_Database::companies_table(),
			Healthcare_Jobs_Database::import_log_table(),
			Healthcare_Jobs_Database::categories_table(),
			Healthcare_Jobs_Database::job_titles_table(),
		);

		foreach ( $tables as $table ) {
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"Expected table {$table} to exist."
			);
		}
	}

	public function test_default_categories_are_seeded_once() {
		$categories = Healthcare_Jobs_Categories::get_names();

		// Each seeded row is resolved against a real term in Directorist's
		// own at_biz_dir-category taxonomy (see the test Directorist stub) -
		// a slug this install doesn't have is skipped rather than invented.
		$this->assertContains( 'Doctors', $categories );
		$this->assertContains( 'Nurses', $categories );
		$this->assertContains( 'Pharmacists', $categories );
		$this->assertContains( 'Dentists', $categories );
		$this->assertContains( 'Receptionists', $categories );
	}

	public function test_seeded_categories_link_to_real_directorist_terms() {
		$categories = Healthcare_Jobs_Categories::get_all();
		foreach ( $categories as $category ) {
			$this->assertNotEmpty( $category['directorist_term_id'], "Category '{$category['name']}' must be linked to a real Directorist term." );
			$this->assertInstanceOf( WP_Term::class, get_term( $category['directorist_term_id'], Healthcare_Jobs_Categories::TAXONOMY ) );
		}
	}

	public function test_external_job_id_is_unique() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$now = current_time( 'mysql', true );
		$row = array(
			'external_job_id' => 'dup-1',
			'source'          => 'theirstack',
			'first_seen_at'   => $now,
			'last_synced_at'  => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		$first = $wpdb->insert( $table, $row );
		$this->assertNotFalse( $first, 'The first insert with real 2.0.0 columns must succeed.' );

		$suppress = $wpdb->suppress_errors( true );
		$result = $wpdb->insert( $table, $row );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $result, 'Inserting a duplicate external_job_id should fail the unique constraint.' );
	}

	public function test_maybe_seed_defaults_backfills_an_upgraded_site_with_no_linked_categories() {
		global $wpdb;
		$cat_table = Healthcare_Jobs_Database::categories_table();

		// Simulate a site that activated a pre-2.0.0 version of this plugin:
		// the old one-time "seeded" flag is already set, but its category
		// rows predate Directorist integration and have no
		// directorist_term_id - this is exactly the state that left a real
		// production import classifying nothing at all.
		update_option( 'healthcare_jobs_defaults_seeded', 1 );
		$wpdb->query( "DELETE FROM {$cat_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$cat_table,
			array(
				'name'       => 'Doctors (legacy)',
				'slug'       => 'doctors-legacy',
				'menu_order' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$this->assertSame( array(), Healthcare_Jobs_Categories::get_classification_rules(), 'Precondition: an unlinked legacy category must not yet produce any classification rule.' );

		Healthcare_Jobs_Database::maybe_seed_defaults();

		$this->assertNotEmpty( Healthcare_Jobs_Categories::get_classification_rules(), 'maybe_seed_defaults() must backfill real Directorist-linked rules even when the old "seeded" flag is already set.' );
	}
}
