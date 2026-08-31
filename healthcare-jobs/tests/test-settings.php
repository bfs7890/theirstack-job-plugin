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

	/**
	 * Regression test: a real browser submits the password field as an
	 * empty string (not a missing key) whenever the admin saves Settings
	 * for any other reason without retyping the API key - this must not
	 * silently wipe a working key.
	 */
	public function test_present_but_empty_api_key_field_does_not_clear_existing_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-keep-me-too' ) );
		Healthcare_Jobs_Settings::save( array( 'api_key' => '', 'default_country' => 'US' ) );

		$this->assertSame( 'sk-keep-me-too', Healthcare_Jobs_Settings::get_api_key() );
	}

	public function test_clear_api_key_checkbox_explicitly_removes_the_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-to-be-removed' ) );
		Healthcare_Jobs_Settings::save( array( 'api_key' => '', 'clear_api_key' => '1' ) );

		$this->assertSame( '', Healthcare_Jobs_Settings::get_api_key() );
	}

	/**
	 * Regression test: the encryption key must not depend on values (like
	 * wp-config.php salts) that can differ between requests/servers -
	 * otherwise a key encrypted on one process can fail to decrypt on
	 * another, surfacing as an unexplained 401 on a later request even
	 * though Test Connection succeeded moments earlier.
	 */
	public function test_encryption_key_is_stable_across_separate_calls() {
		$encrypted_first  = Healthcare_Jobs_Settings::encrypt( 'sk-stability-check' );
		$encrypted_second = Healthcare_Jobs_Settings::encrypt( 'sk-stability-check' );

		// Ciphertext differs each time (random IV), but both must decrypt
		// back to the same plaintext using the same stored secret.
		$this->assertSame( 'sk-stability-check', Healthcare_Jobs_Settings::decrypt( $encrypted_first ) );
		$this->assertSame( 'sk-stability-check', Healthcare_Jobs_Settings::decrypt( $encrypted_second ) );
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

	/**
	 * Simulates a key encrypted under the old, pre-fix salt-derived scheme
	 * and confirms the one-time migration recovers it under the new,
	 * stable secret so the admin does not have to re-enter it.
	 */
	public function test_migration_recovers_a_legacy_salt_encrypted_key() {
		$legacy_secret = '';
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$legacy_secret .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$legacy_secret .= SECURE_AUTH_KEY;
		}
		if ( '' === $legacy_secret ) {
			$legacy_secret = DB_NAME . DB_HOST;
		}
		$legacy_key = hash( 'sha256', $legacy_secret, true );

		$iv        = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( 'sk-legacy-value', 'aes-256-cbc', $legacy_key, OPENSSL_RAW_DATA, $iv );
		$stored    = 'enc:' . base64_encode( $iv . $encrypted );

		update_option( Healthcare_Jobs_Settings::OPTION_KEY, array( 'api_key_encrypted' => $stored ) );
		delete_option( 'healthcare_jobs_api_key_migrated' );

		Healthcare_Jobs_Settings::maybe_migrate_api_key_encryption();

		$this->assertSame( 'sk-legacy-value', Healthcare_Jobs_Settings::get_api_key() );
	}
}
