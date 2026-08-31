<?php
/**
 * Plugin settings: storage, sanitisation, and secure API key handling.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Settings {

	const OPTION_KEY = 'healthcare_jobs_settings';
	const CAPABILITY = 'manage_healthcare_jobs';

	/**
	 * Returns the merged settings array (stored options over hard defaults).
	 *
	 * @return array
	 */
	public static function get_all() {
		$defaults = self::get_defaults();
		$stored   = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, $defaults );
	}

	/**
	 * Hard-coded defaults used until an administrator saves settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'api_key_encrypted'    => '',
			'default_country'      => 'GB',
			'default_job_age_days' => 30,
			'max_jobs_per_import'  => 200,
			'auto_import_enabled'  => 1,
			'import_frequency'     => 'six_hours',
			'default_job_status'   => 'open',
			'categories'           => array(),
			'job_titles'           => array(),
			'results_per_page'     => 20,
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * Reads one setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not set.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persists the full settings array after sanitisation. Callers must have
	 * already checked the manage capability and a valid nonce.
	 *
	 * @param array $input Raw input, typically from $_POST.
	 * @return array Sanitised settings that were saved.
	 */
	public static function save( array $input ) {
		$current = self::get_all();

		$sanitised = $current;

		if ( isset( $input['default_country'] ) ) {
			$sanitised['default_country'] = strtoupper( preg_replace( '/[^A-Za-z]/', '', substr( $input['default_country'], 0, 2 ) ) );
		}

		if ( isset( $input['default_job_age_days'] ) ) {
			$sanitised['default_job_age_days'] = max( 1, min( 365, absint( $input['default_job_age_days'] ) ) );
		}

		if ( isset( $input['max_jobs_per_import'] ) ) {
			$sanitised['max_jobs_per_import'] = max( 1, min( 5000, absint( $input['max_jobs_per_import'] ) ) );
		}

		$sanitised['auto_import_enabled']       = ! empty( $input['auto_import_enabled'] ) ? 1 : 0;
		$sanitised['delete_data_on_uninstall']  = ! empty( $input['delete_data_on_uninstall'] ) ? 1 : 0;

		if ( isset( $input['import_frequency'] ) && array_key_exists( $input['import_frequency'], Healthcare_Jobs_Cron::get_schedule_choices() ) ) {
			$sanitised['import_frequency'] = sanitize_key( $input['import_frequency'] );
		}

		if ( isset( $input['default_job_status'] ) ) {
			$sanitised['default_job_status'] = in_array( $input['default_job_status'], array( 'open', 'all' ), true ) ? $input['default_job_status'] : 'open';
		}

		if ( isset( $input['results_per_page'] ) ) {
			$sanitised['results_per_page'] = max( 5, min( 100, absint( $input['results_per_page'] ) ) );
		}

		// API key: only overwrite if a new, non-masked value was submitted.
		if ( isset( $input['api_key'] ) ) {
			$raw_key = trim( (string) $input['api_key'] );
			if ( '' !== $raw_key && false === strpos( $raw_key, '•' ) ) {
				$sanitised['api_key_encrypted'] = self::encrypt( sanitize_text_field( $raw_key ) );
			} elseif ( '' === $raw_key ) {
				$sanitised['api_key_encrypted'] = '';
			}
		}

		update_option( self::OPTION_KEY, $sanitised, false );

		Healthcare_Jobs_Cron::reschedule();

		return $sanitised;
	}

	/**
	 * Returns the active TheirStack API key.
	 *
	 * A PHP constant (e.g. defined in wp-config.php) always takes priority
	 * over the database, so site owners can keep the key entirely out of
	 * the database if they prefer environment-based configuration.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( defined( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY' ) && HEALTHCARE_JOBS_THEIRSTACK_API_KEY ) {
			return (string) HEALTHCARE_JOBS_THEIRSTACK_API_KEY;
		}

		$encrypted = self::get( 'api_key_encrypted', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	/**
	 * True when the API key is coming from a wp-config.php constant rather
	 * than the database (the recommended, more secure configuration).
	 *
	 * @return bool
	 */
	public static function api_key_is_from_constant() {
		return defined( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY' ) && HEALTHCARE_JOBS_THEIRSTACK_API_KEY;
	}

	/**
	 * True when an API key is configured via either method.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	/**
	 * Derives a per-site encryption key from WordPress's own secret salts,
	 * so the encrypted value in the database is useless without also having
	 * access to wp-config.php.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function derive_key() {
		$secret = '';
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$secret .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$secret .= SECURE_AUTH_KEY;
		}
		if ( '' === $secret ) {
			// Extremely unlikely on a real WordPress install, but avoid a fatal.
			$secret = DB_NAME . DB_HOST;
		}
		return hash( 'sha256', $secret, true );
	}

	/**
	 * Encrypts a value for storage using AES-256-CBC when OpenSSL is
	 * available, falling back to base64 (obfuscation only) otherwise.
	 *
	 * @param string $value Plaintext.
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $value );
		}

		$iv        = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', self::derive_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return 'b64:' . base64_encode( $value );
		}

		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Reverses encrypt().
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return base64_decode( substr( $stored, 4 ) );
		}

		if ( 0 === strpos( $stored, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 4 ) );
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return '';
			}
			$iv        = substr( $raw, 0, 16 );
			$data      = substr( $raw, 16 );
			$decrypted = openssl_decrypt( $data, 'aes-256-cbc', self::derive_key(), OPENSSL_RAW_DATA, $iv );
			return false === $decrypted ? '' : $decrypted;
		}

		return '';
	}

	/**
	 * A masked representation of the stored key, safe to render in admin
	 * screens (never the real value).
	 *
	 * @return string
	 */
	public static function get_masked_api_key() {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return '';
		}
		$len = strlen( $key );
		$tail = substr( $key, max( 0, $len - 4 ), 4 );
		return str_repeat( '•', 20 ) . $tail;
	}
}
