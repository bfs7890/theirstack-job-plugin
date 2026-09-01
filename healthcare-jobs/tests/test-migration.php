<?php
/**
 * Tests for the one-time migration of pre-Directorist jobs (rows from a
 * site upgraded from before plugin version 2.0.0, where wp_healthcare_jobs
 * was still its own authoritative job store) into real Directorist
 * listings.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Migration_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Healthcare_Jobs_Classifier::clear_cache();
	}

	/**
	 * Adds the pre-2.0.0 legacy columns to the (now sync-tracking-only)
	 * jobs table, simulating a site upgraded from an earlier version where
	 * dbDelta() left them physically in place.
	 */
	private function add_legacy_columns() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$wpdb->query( "ALTER TABLE {$table}
			ADD COLUMN title VARCHAR(255) NULL,
			ADD COLUMN description LONGTEXT NULL,
			ADD COLUMN company_name VARCHAR(255) NULL,
			ADD COLUMN location VARCHAR(255) NULL,
			ADD COLUMN status VARCHAR(20) NULL,
			ADD COLUMN is_closed TINYINT(1) NULL,
			ADD COLUMN posted_at DATETIME NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function insert_legacy_row( array $overrides = array() ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$now   = current_time( 'mysql', true );

		$row = array_merge(
			array(
				'external_job_id' => 'legacy-' . wp_generate_password( 8, false ),
				'source'          => 'theirstack',
				'title'           => 'Registered Nurse',
				'description'     => 'A legacy nursing role.',
				'company_name'    => 'Legacy Health Trust',
				'location'        => 'London, UK',
				'status'          => 'active',
				'is_closed'       => 0,
				'posted_at'       => $now,
				'first_seen_at'   => $now,
				'last_synced_at'  => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			$overrides
		);
		$wpdb->insert( $table, $row );
		return $row['external_job_id'];
	}

	public function test_no_legacy_columns_means_nothing_pending() {
		$this->assertSame( 0, Healthcare_Jobs_Migration::count_pending() );

		$result = Healthcare_Jobs_Migration::run();
		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( 0, $result['stats']['jobs_found'] );
	}

	public function test_pending_legacy_rows_are_counted() {
		$this->add_legacy_columns();
		$this->insert_legacy_row();
		$this->insert_legacy_row();

		$this->assertSame( 2, Healthcare_Jobs_Migration::count_pending() );
	}

	public function test_migration_creates_directorist_listings_from_legacy_rows() {
		$this->add_legacy_columns();
		$external_id = $this->insert_legacy_row( array( 'title' => 'Staff Nurse' ) );

		$result = Healthcare_Jobs_Migration::run();

		$this->assertSame( 1, $result['stats']['jobs_created'] );

		$post_id = Healthcare_Jobs_Directorist_Sync::find_existing_post_id( $external_id );
		$this->assertNotSame( 0, $post_id );
		$this->assertSame( 'Staff Nurse', get_the_title( $post_id ) );
		$this->assertSame( 'at_biz_dir', get_post_type( $post_id ) );
	}

	public function test_migration_re_classifies_rather_than_trusting_the_legacy_category_column() {
		$this->add_legacy_columns();
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN category VARCHAR(120) NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// A legacy row mis-tagged "Doctors" by the old naive classifier for
		// a title that is not a doctor role at all - migration must ignore
		// this stored category and re-classify from the title itself.
		$external_id = $this->insert_legacy_row(
			array(
				'title'    => 'IT Consultant',
				'category' => 'Doctors',
			)
		);

		$result = Healthcare_Jobs_Migration::run();

		$this->assertSame( 1, $result['stats']['jobs_skipped'], 'A non-healthcare title must be skipped, not migrated under its old (wrong) category.' );
		$this->assertSame( 0, Healthcare_Jobs_Directorist_Sync::find_existing_post_id( $external_id ) );
	}

	public function test_legacy_rows_are_never_deleted() {
		$this->add_legacy_columns();
		$external_id = $this->insert_legacy_row();

		Healthcare_Jobs_Migration::run();

		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE external_job_id = %s", $external_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotNull( $row, 'The legacy row must survive migration.' );
		$this->assertSame( 'Registered Nurse', $row['title'], 'The legacy column data itself must never be cleared.' );
	}

	public function test_already_migrated_rows_are_not_reprocessed() {
		$this->add_legacy_columns();
		$this->insert_legacy_row();

		Healthcare_Jobs_Migration::run();
		$this->assertSame( 0, Healthcare_Jobs_Migration::count_pending() );

		$second = Healthcare_Jobs_Migration::run();
		$this->assertSame( 0, $second['stats']['jobs_found'] );
	}
}
