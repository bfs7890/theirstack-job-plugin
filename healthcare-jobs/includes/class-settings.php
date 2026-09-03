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
			'adzuna_app_id'            => '',
			'adzuna_app_key_encrypted' => '',
			'adzuna_import_enabled'    => 0,
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
		// A blank submission on its own is NOT treated as "clear the key" -
		// the field is deliberately rendered blank (with a masked
		// placeholder) on every page load, so saving any other setting
		// without retyping the key must never silently delete it. Clearing
		// requires the explicit "Remove API Key" checkbox below.
		if ( isset( $input['api_key'] ) ) {
			$raw_key = trim( (string) $input['api_key'] );
			if ( '' !== $raw_key && false === strpos( $raw_key, '•' ) ) {
				$sanitised['api_key_encrypted'] = self::encrypt( sanitize_text_field( $raw_key ) );
			}
		}
		if ( ! empty( $input['clear_api_key'] ) ) {
			$sanitised['api_key_encrypted'] = '';
		}

		if ( isset( $input['adzuna_app_id'] ) ) {
			$sanitised['adzuna_app_id'] = sanitize_text_field( trim( (string) $input['adzuna_app_id'] ) );
		}

		$sanitised['adzuna_import_enabled'] = ! empty( $input['adzuna_import_enabled'] ) ? 1 : 0;

		// Adzuna App Key: same "blank submission never clears it, only the
		// explicit checkbox does" rule as the TheirStack API key above.
		if ( isset( $input['adzuna_app_key'] ) ) {
			$raw_key = trim( (string) $input['adzuna_app_key'] );
			if ( '' !== $raw_key && false === strpos( $raw_key, '•' ) ) {
				$sanitised['adzuna_app_key_encrypted'] = self::encrypt( sanitize_text_field( $raw_key ) );
			}
		}
		if ( ! empty( $input['clear_adzuna_app_key'] ) ) {
			$sanitised['adzuna_app_key_encrypted'] = '';
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
			// A wp-config.php define() can pick up an accidental trailing
			// space/newline from copy-pasting; trim defensively on every
			// read (never otherwise modify the key).
			return trim( (string) HEALTHCARE_JOBS_THEIRSTACK_API_KEY );
		}

		$encrypted = self::get( 'api_key_encrypted', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}

		return trim( self::decrypt( $encrypted ) );
	}

	/**
	 * Flags characters in the configured key that would silently break the
	 * Authorization header even though length/tail look plausible: quote
	 * marks (from pasting a value that included its surrounding quotes),
	 * whitespace/control characters other than the outer trim() already
	 * removes, and literal HTML-entity-encoded quotes. Used only for the
	 * masked diagnostics - the key itself is never altered beyond trim().
	 *
	 * @return string[] Human-readable list of problems found, empty if none.
	 */
	public static function get_api_key_warnings() {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return array();
		}

		$warnings = array();

		if ( preg_match( '/[\'"`]/', $key ) ) {
			$warnings[] = __( 'contains a quote character - if you pasted the key with quotes around it, remove them', 'healthcare-jobs' );
		}
		if ( preg_match( '/&quot;|&#0?39;|%22|%27/i', $key ) ) {
			$warnings[] = __( 'contains an HTML-entity or URL-encoded quote sequence', 'healthcare-jobs' );
		}
		if ( preg_match( '/\s/', $key ) ) {
			$warnings[] = __( 'contains internal whitespace or a line break', 'healthcare-jobs' );
		}
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $key ) ) {
			$warnings[] = __( 'contains a non-printable control character', 'healthcare-jobs' );
		}

		return $warnings;
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
	 * Returns the active Adzuna App ID.
	 *
	 * A PHP constant (HEALTHCARE_JOBS_ADZUNA_APP_ID) always takes priority
	 * over the database, mirroring get_api_key().
	 *
	 * @return string
	 */
	public static function get_adzuna_app_id() {
		if ( defined( 'HEALTHCARE_JOBS_ADZUNA_APP_ID' ) && HEALTHCARE_JOBS_ADZUNA_APP_ID ) {
			return trim( (string) HEALTHCARE_JOBS_ADZUNA_APP_ID );
		}
		return trim( (string) self::get( 'adzuna_app_id', '' ) );
	}

	/**
	 * Returns the active Adzuna App Key.
	 *
	 * A PHP constant (HEALTHCARE_JOBS_ADZUNA_APP_KEY) always takes priority
	 * over the database, mirroring get_api_key().
	 *
	 * @return string
	 */
	public static function get_adzuna_app_key() {
		if ( defined( 'HEALTHCARE_JOBS_ADZUNA_APP_KEY' ) && HEALTHCARE_JOBS_ADZUNA_APP_KEY ) {
			return trim( (string) HEALTHCARE_JOBS_ADZUNA_APP_KEY );
		}

		$encrypted = self::get( 'adzuna_app_key_encrypted', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}

		return trim( self::decrypt( $encrypted ) );
	}

	/**
	 * True when the Adzuna App Key is coming from a wp-config.php constant
	 * rather than the database.
	 *
	 * @return bool
	 */
	public static function adzuna_app_key_is_from_constant() {
		return defined( 'HEALTHCARE_JOBS_ADZUNA_APP_KEY' ) && HEALTHCARE_JOBS_ADZUNA_APP_KEY;
	}

	/**
	 * True when both the Adzuna App ID and App Key are configured.
	 *
	 * @return bool
	 */
	public static function has_adzuna_credentials() {
		return '' !== self::get_adzuna_app_id() && '' !== self::get_adzuna_app_key();
	}

	/**
	 * True when Adzuna import is both switched on and actually configured -
	 * the single check callers (cron, admin AJAX) should use to decide
	 * whether to run the Adzuna importer at all.
	 *
	 * @return bool
	 */
	public static function adzuna_import_enabled() {
		return ! empty( self::get( 'adzuna_import_enabled', 0 ) ) && self::has_adzuna_credentials();
	}

	/**
	 * A masked representation of the stored Adzuna App Key, safe to render
	 * in admin screens.
	 *
	 * @return string
	 */
	public static function get_masked_adzuna_app_key() {
		$key = self::get_adzuna_app_key();
		if ( '' === $key ) {
			return '';
		}
		$len  = strlen( $key );
		$tail = substr( $key, max( 0, $len - 4 ), 4 );
		return str_repeat( '•', 20 ) . $tail;
	}

	const ENCRYPTION_SECRET_OPTION = 'healthcare_jobs_encryption_secret';

	/**
	 * Returns the random secret used to derive the encryption key, creating
	 * it once and storing it in the database (not autoloaded).
	 *
	 * This is intentionally a dedicated stored secret rather than being
	 * derived from AUTH_KEY/SECURE_AUTH_KEY: those wp-config.php constants
	 * can legitimately differ between servers behind a load balancer, or
	 * get rotated by a security tool, and either case silently breaks
	 * decryption on whichever request/server did not encrypt the value -
	 * the exact "Test Connection works, the next real request gets a 401"
	 * symptom this fixes. A value read from the shared database is
	 * guaranteed identical on every server and every cron run.
	 *
	 * @return string
	 */
	private static function get_encryption_secret() {
		$secret = get_option( self::ENCRYPTION_SECRET_OPTION );

		if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
			return $secret;
		}

		$secret = function_exists( 'random_bytes' ) ? base64_encode( random_bytes( 32 ) ) : wp_generate_password( 64, true, true );
		update_option( self::ENCRYPTION_SECRET_OPTION, $secret, false );

		return $secret;
	}

	/**
	 * Derives the AES key from the stored secret.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function derive_key() {
		return hash( 'sha256', self::get_encryption_secret(), true );
	}

	/**
	 * The original (pre-fix) key derivation, kept only so
	 * maybe_migrate_api_key_encryption() can read values that were
	 * encrypted before this fix and re-encrypt them under the new,
	 * stable secret.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function derive_key_legacy() {
		$secret = '';
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$secret .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$secret .= SECURE_AUTH_KEY;
		}
		if ( '' === $secret ) {
			$secret = DB_NAME . DB_HOST;
		}
		return hash( 'sha256', $secret, true );
	}

	/**
	 * One-time migration from the legacy salt-derived encryption key to the
	 * new stable, database-stored secret. Safe to call on every request
	 * (checks a flag and returns immediately after the first successful
	 * run); only touches the stored API key, nothing else.
	 *
	 * @return void
	 */
	public static function maybe_migrate_api_key_encryption() {
		if ( get_option( 'healthcare_jobs_api_key_migrated' ) ) {
			return;
		}

		$encrypted = self::get( 'api_key_encrypted', '' );

		if ( ! empty( $encrypted ) && 0 === strpos( $encrypted, 'enc:' ) ) {
			$raw = base64_decode( substr( $encrypted, 4 ) );
			if ( false !== $raw && strlen( $raw ) >= 17 ) {
				$iv        = substr( $raw, 0, 16 );
				$data      = substr( $raw, 16 );
				$decrypted = openssl_decrypt( $data, 'aes-256-cbc', self::derive_key_legacy(), OPENSSL_RAW_DATA, $iv );

				// A successful legacy decrypt yields a short, printable
				// token; garbage from a mismatched key will not.
				if ( false !== $decrypted && strlen( $decrypted ) > 0 && strlen( $decrypted ) < 500 && ctype_print( $decrypted ) ) {
					$settings                       = self::get_all();
					$settings['api_key_encrypted']  = self::encrypt( $decrypted );
					update_option( self::OPTION_KEY, $settings, false );
				}
			}
		}

		update_option( 'healthcare_jobs_api_key_migrated', 1 );
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
