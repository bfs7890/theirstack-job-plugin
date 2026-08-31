<?php
/**
 * Tests for settings storage, API key encryption, and capability wiring.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Settings_Test extends WP_UnitTestCase {

	public function test_api_key_round_trips_through_encryption() {
		$encrypted = Healthcare_Jobs_Settings::encrypt( 'sk-test-12345' );
		$this->assertNotSame( 'sk-test-12345', $encrypted, 'Stored value must not be plaintext.' );
		$this->assertSame( 'sk-test-12345', Healthcare_Jobs_Settings::decrypt( $encrypted ) );
	}

	public function test_save_persists_and_masks_api_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-live-abc123' ) );
		$this->assertSame( 'sk-live-abc123', Healthcare_Jobs_Settings::get_api_key() );

		$masked = Healthcare_Jobs_Settings::get_masked_api_key();
		$this->assertStringNotContainsString( 'sk-live-abc123', $masked );
		$this->assertStringEndsWith( 'c123', $masked );
	}

	public function test_blank_api_key_input_does_not_clear_existing_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-keep-me' ) );
		Healthcare_Jobs_Settings::save( array( 'default_country' => 'US' ) );

		$this->assertSame( 'sk-keep-me', Healthcare_Jobs_Settings::get_api_key() );
	}

	public function test_constant_overrides_database_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-database' ) );

		if ( ! defined( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY' ) ) {
			define( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY', 'sk-from-constant' );
		}

		$this->assertSame( 'sk-from-constant', Healthcare_Jobs_Settings::get_api_key() );
		$this->assertTrue( Healthcare_Jobs_Settings::api_key_is_from_constant() );
	}

	public function test_numeric_settings_are_bounded() {
		$saved = Healthcare_Jobs_Settings::save(
			array(
				'default_job_age_days' => 99999,
				'max_jobs_per_import'  => -5,
			)
		);

		$this->assertSame( 365, $saved['default_job_age_days'] );
		$this->assertSame( 1, $saved['max_jobs_per_import'] );
	}

	public function test_administrator_receives_capability_on_activation() {
		healthcare_jobs_activate();
		$role = get_role( 'administrator' );
		$this->assertTrue( $role->has_cap( Healthcare_Jobs_Settings::CAPABILITY ) );
	}

	public function test_subscriber_does_not_have_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_userdata( $user_id );
		$this->assertFalse( $user->has_cap( Healthcare_Jobs_Settings::CAPABILITY ) );
	}
}
