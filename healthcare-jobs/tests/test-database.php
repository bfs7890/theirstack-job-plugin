<?php
/**
 * Tests for schema creation and default seeding.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Database_Test extends WP_UnitTestCase {

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

		$this->assertContains( 'Doctors', $categories );
		$this->assertContains( 'Nursing', $categories );
		$this->assertContains( 'Allied Health', $categories );
		$this->assertContains( 'Pharmacy', $categories );
		$this->assertContains( 'Dental', $categories );
		$this->assertContains( 'Healthcare Management', $categories );
	}

	public function test_external_job_id_is_unique() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'external_job_id' => 'dup-1',
				'slug'             => 'dup-1-job',
				'title'            => 'Duplicate Test',
				'first_seen_at'    => $now,
				'last_updated_at'  => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		$suppress = $wpdb->suppress_errors( true );
		$result = $wpdb->insert(
			$table,
			array(
				'external_job_id' => 'dup-1',
				'slug'             => 'dup-1-job-2',
				'title'            => 'Duplicate Test 2',
				'first_seen_at'    => $now,
				'last_updated_at'  => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $result, 'Inserting a duplicate external_job_id should fail the unique constraint.' );
	}
}
