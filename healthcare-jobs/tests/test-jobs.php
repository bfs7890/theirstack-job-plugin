<?php
/**
 * Tests for job upsert/dedupe, slugs, status changes, and expiration.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Jobs_Test extends WP_UnitTestCase {

	private function sample_job( $overrides = array() ) {
		return array_merge(
			array(
				'external_job_id' => 'ts-1001',
				'source'          => 'theirstack',
				'job_source_type' => 'aggregated',
				'title'           => 'Registered Nurse',
				'company_name'    => 'Acme Health',
				'category'        => 'Nursing',
				'status'          => Healthcare_Jobs_Jobs::STATUS_ACTIVE,
				'is_closed'       => 0,
				'posted_at'       => current_time( 'mysql', true ),
				'raw_data'        => wp_json_encode( array( 'id' => 'ts-1001' ) ),
			),
			$overrides
		);
	}

	public function test_upsert_inserts_new_job() {
		$result = Healthcare_Jobs_Jobs::upsert( $this->sample_job() );
		$this->assertTrue( $result['is_new'] );

		$job = Healthcare_Jobs_Jobs::get( $result['id'] );
		$this->assertSame( 'Registered Nurse', $job['title'] );
		$this->assertNotEmpty( $job['slug'] );
	}

	public function test_upsert_same_external_id_updates_not_duplicates() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$first  = Healthcare_Jobs_Jobs::upsert( $this->sample_job() );
		$second = Healthcare_Jobs_Jobs::upsert( $this->sample_job( array( 'title' => 'Registered Nurse (Updated)' ) ) );

		$this->assertFalse( $second['is_new'] );
		$this->assertSame( $first['id'], $second['id'] );

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE external_job_id = %s", 'ts-1001' ) );
		$this->assertSame( 1, $count );

		$job = Healthcare_Jobs_Jobs::get( $second['id'] );
		$this->assertSame( 'Registered Nurse (Updated)', $job['title'] );
	}

	public function test_generate_unique_slug_avoids_collisions() {
		Healthcare_Jobs_Jobs::upsert( $this->sample_job( array( 'external_job_id' => 'ts-a', 'title' => 'Staff Nurse', 'city' => 'London' ) ) );
		$second = Healthcare_Jobs_Jobs::upsert( $this->sample_job( array( 'external_job_id' => 'ts-b', 'title' => 'Staff Nurse', 'city' => 'London' ) ) );

		$job_a = Healthcare_Jobs_Jobs::find_by_external_id( 'ts-a' );
		$job_b = Healthcare_Jobs_Jobs::get( $second['id'] );

		$this->assertNotSame( $job_a['slug'], $job_b['slug'] );
	}

	public function test_set_status_deactivate_and_delete() {
		$result = Healthcare_Jobs_Jobs::upsert( $this->sample_job() );

		Healthcare_Jobs_Jobs::set_status( $result['id'], Healthcare_Jobs_Jobs::STATUS_CLOSED );
		$job = Healthcare_Jobs_Jobs::get( $result['id'] );
		$this->assertSame( 'closed', $job['status'] );
		$this->assertEquals( 1, $job['is_closed'] );

		Healthcare_Jobs_Jobs::delete( $result['id'] );
		$this->assertNull( Healthcare_Jobs_Jobs::get( $result['id'] ) );
	}

	public function test_expire_by_max_age() {
		$old_job = Healthcare_Jobs_Jobs::upsert(
			$this->sample_job(
				array(
					'external_job_id' => 'ts-old',
					'posted_at'       => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ),
				)
			)
		);
		$new_job = Healthcare_Jobs_Jobs::upsert(
			$this->sample_job(
				array(
					'external_job_id' => 'ts-new',
					'posted_at'       => current_time( 'mysql', true ),
				)
			)
		);

		$expired_count = Healthcare_Jobs_Jobs::expire_by_max_age( 30 );

		$this->assertGreaterThanOrEqual( 1, $expired_count );
		$this->assertSame( 'expired', Healthcare_Jobs_Jobs::get( $old_job['id'] )['status'] );
		$this->assertSame( 'active', Healthcare_Jobs_Jobs::get( $new_job['id'] )['status'] );
	}

	public function test_mark_closed_by_external_ids() {
		$job = Healthcare_Jobs_Jobs::upsert( $this->sample_job( array( 'external_job_id' => 'ts-close-me' ) ) );

		Healthcare_Jobs_Jobs::mark_closed_by_external_ids( array( 'ts-close-me' ) );

		$this->assertSame( 'closed', Healthcare_Jobs_Jobs::get( $job['id'] )['status'] );
	}

	public function test_expired_and_closed_jobs_are_never_hard_deleted_by_expiration() {
		$job = Healthcare_Jobs_Jobs::upsert(
			$this->sample_job(
				array(
					'external_job_id' => 'ts-history',
					'posted_at'       => gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) ),
				)
			)
		);

		Healthcare_Jobs_Jobs::expire_by_max_age( 30 );

		$this->assertNotNull( Healthcare_Jobs_Jobs::get( $job['id'] ), 'Expiration must keep the historical record.' );
	}
}
