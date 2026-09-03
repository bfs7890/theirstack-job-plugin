<?php
/**
 * Security tests: capability checks, nonce enforcement, and API key
 * secrecy across admin/AJAX surfaces.
 *
 * These call the admin-post/AJAX handlers directly (rather than through
 * admin-ajax.php) without defining DOING_AJAX, so a rejected request's
 * wp_die()/wp_send_json_*() call is caught here as the standard
 * WPDieException used by the WordPress test suite's default die handler.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Security_Test extends WP_UnitTestCase {

	public function test_ajax_test_connection_rejects_unprivileged_user() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_REQUEST['nonce'] = wp_create_nonce( Healthcare_Jobs_Admin::NONCE_ACTION );

		$this->expectException( WPDieException::class );
		Healthcare_Jobs_Admin::ajax_test_connection();
	}

	public function test_ajax_test_adzuna_connection_rejects_unprivileged_user() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_REQUEST['nonce'] = wp_create_nonce( Healthcare_Jobs_Admin::NONCE_ACTION );

		$this->expectException( WPDieException::class );
		Healthcare_Jobs_Admin::ajax_test_adzuna_connection();
	}

	public function test_ajax_run_import_rejects_unprivileged_user() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_REQUEST['nonce'] = wp_create_nonce( Healthcare_Jobs_Admin::NONCE_ACTION );

		$this->expectException( WPDieException::class );
		Healthcare_Jobs_Admin::ajax_run_import();
	}

	public function test_ajax_test_connection_rejects_bad_nonce_for_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_REQUEST['nonce'] = 'not-a-valid-nonce';

		$this->expectException( WPDieException::class );
		Healthcare_Jobs_Admin::ajax_test_connection();
	}

	public function test_admin_post_handler_rejects_missing_capability() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->expectException( WPDieException::class );
		Healthcare_Jobs_Admin::handle_save_settings();
	}

	public function test_admin_post_handler_rejects_missing_nonce() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_POST = array();

		$this->expectException( WPDieException::class );
		Healthcare_Jobs_Admin::handle_save_settings();
	}

	public function test_admin_with_valid_nonce_and_capability_is_allowed() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_POST = array(
			'_wpnonce'         => wp_create_nonce( Healthcare_Jobs_Admin::NONCE_ACTION ),
			'default_country'  => 'IE',
		);

		// handle_save_settings() redirects (wp_safe_redirect + exit) on
		// success; the test die handler turns that exit into an exception
		// too, but crucially NOT until after Settings::save() has run.
		try {
			Healthcare_Jobs_Admin::handle_save_settings();
		} catch ( WPDieException $e ) {
			// Expected: the handler calls exit after redirecting.
		}

		$this->assertSame( 'IE', Healthcare_Jobs_Settings::get( 'default_country' ) );
	}

	public function test_public_search_ajax_never_reveals_api_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-should-never-appear' ) );
		$_GET = array( 'keyword' => 'nurse' );

		ob_start();
		try {
			Healthcare_Jobs_Shortcode::ajax_search();
		} catch ( WPDieException $e ) {
			// wp_send_json_success() ends in wp_die(); expected in tests.
		}
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'sk-should-never-appear', $output );
	}

	public function test_error_messages_never_contain_the_api_key() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-secret-value' ) );

		$callback = function () {
			return array( 'response' => array( 'code' => 500 ), 'body' => '{"message":"Server error"}' );
		};
		add_filter( 'pre_http_request', $callback );

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->search_jobs( array( 'limit' => 1 ) );

		remove_filter( 'pre_http_request', $callback );

		$this->assertWPError( $result );
		$this->assertStringNotContainsString( 'sk-secret-value', $result->get_error_message() );
	}

	public function test_logger_redacts_api_key_from_debug_output() {
		Healthcare_Jobs_Settings::save( array( 'api_key' => 'sk-redact-me' ) );
		$redacted = Healthcare_Jobs_Logger::redact( 'Request failed using key sk-redact-me in headers' );
		$this->assertStringNotContainsString( 'sk-redact-me', $redacted );
	}

	public function test_logger_redacts_adzuna_app_key_from_debug_output() {
		Healthcare_Jobs_Settings::save( array( 'adzuna_app_key' => 'app-key-redact-me' ) );
		$redacted = Healthcare_Jobs_Logger::redact( 'Adzuna request failed using key app-key-redact-me in query string' );
		$this->assertStringNotContainsString( 'app-key-redact-me', $redacted );
	}

	public function test_adzuna_error_messages_never_contain_the_app_key() {
		Healthcare_Jobs_Settings::save( array( 'adzuna_app_id' => 'app-id-123', 'adzuna_app_key' => 'app-key-secret-value' ) );

		$callback = function () {
			return array( 'response' => array( 'code' => 500 ), 'body' => '{"display":"Server error"}' );
		};
		add_filter( 'pre_http_request', $callback );

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->search_jobs( array( 'country_code' => 'GB', 'page' => 1 ) );

		remove_filter( 'pre_http_request', $callback );

		$this->assertWPError( $result );
		$this->assertStringNotContainsString( 'app-key-secret-value', $result->get_error_message() );
	}

	public function test_healthcare_jobs_capability_is_a_dedicated_capability() {
		// Confirms the plugin does not simply gate on manage_options, so a
		// future non-admin "healthcare jobs manager" role can be granted
		// access without full site admin rights.
		$this->assertSame( 'manage_healthcare_jobs', Healthcare_Jobs_Settings::CAPABILITY );
	}
}
