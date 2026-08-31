<?php
/**
 * Tests for company upsert/matching and evidence-based employer classification.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Companies_Test extends WP_UnitTestCase {

	public function test_upsert_creates_new_company() {
		$id = Healthcare_Jobs_Companies::upsert(
			array(
				'external_company_id' => 'co-1',
				'company_name'        => 'Acme Health Ltd',
				'website'             => 'https://acmehealth.example.com',
			)
		);

		$company = Healthcare_Jobs_Companies::get( $id );
		$this->assertSame( 'Acme Health Ltd', $company['company_name'] );
	}

	public function test_upsert_matches_by_external_id_not_duplicated() {
		$first  = Healthcare_Jobs_Companies::upsert( array( 'external_company_id' => 'co-2', 'company_name' => 'Beta Clinics' ) );
		$second = Healthcare_Jobs_Companies::upsert( array( 'external_company_id' => 'co-2', 'company_name' => 'Beta Clinics Renamed' ) );

		$this->assertSame( $first, $second );
		$this->assertSame( 'Beta Clinics Renamed', Healthcare_Jobs_Companies::get( $first )['company_name'] );
	}

	public function test_upsert_matches_by_name_when_no_external_id() {
		$first  = Healthcare_Jobs_Companies::upsert( array( 'company_name' => 'Gamma Dental' ) );
		$second = Healthcare_Jobs_Companies::upsert( array( 'company_name' => 'Gamma Dental' ) );

		$this->assertSame( $first, $second, 'A company can have multiple jobs and must resolve to the same record.' );
	}

	public function test_existing_field_is_not_blanked_by_a_sparser_update() {
		$id = Healthcare_Jobs_Companies::upsert(
			array(
				'company_name' => 'Delta Pharmacy Group',
				'website'      => 'https://delta.example.com',
				'industry'     => 'Pharmacy',
			)
		);

		Healthcare_Jobs_Companies::upsert( array( 'company_name' => 'Delta Pharmacy Group' ) );

		$company = Healthcare_Jobs_Companies::get( $id );
		$this->assertSame( 'https://delta.example.com', $company['website'] );
		$this->assertSame( 'Pharmacy', $company['industry'] );
	}

	public function test_nhs_classification_requires_evidence() {
		$this->assertSame( 'nhs', Healthcare_Jobs_Companies::classify_employer_type( 'Central London NHS Trust' ) );
		$this->assertSame( '', Healthcare_Jobs_Companies::classify_employer_type( 'Acme Health Ltd' ), 'Must not assume private/NHS without evidence.' );
	}

	public function test_active_jobs_counter_recalculates() {
		$company_id = Healthcare_Jobs_Companies::upsert( array( 'company_name' => 'Epsilon Care Home' ) );

		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$now   = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'external_job_id' => 'job-epsilon-1',
				'slug'             => 'job-epsilon-1',
				'title'            => 'Care Assistant',
				'company_id'       => $company_id,
				'status'           => 'active',
				'first_seen_at'    => $now,
				'last_updated_at'  => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		Healthcare_Jobs_Companies::recalculate_active_jobs( $company_id );

		$company = Healthcare_Jobs_Companies::get( $company_id );
		$this->assertSame( 1, (int) $company['active_jobs'] );
	}
}
