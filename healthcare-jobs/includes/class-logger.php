<?php
/**
 * Logging: import history storage and safe diagnostic logging.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Logger {

	/**
	 * Strings that must never appear in a logged message. Defence in depth
	 * on top of never passing the API key into log calls in the first place.
	 *
	 * @var string[]
	 */
	private static function get_secrets() {
		$secrets = array();

		$key = Healthcare_Jobs_Settings::get_api_key();
		if ( ! empty( $key ) ) {
			$secrets[] = $key;
		}

		$adzuna_key = Healthcare_Jobs_Settings::get_adzuna_app_key();
		if ( ! empty( $adzuna_key ) ) {
			$secrets[] = $adzuna_key;
		}

		return $secrets;
	}

	/**
	 * Redacts any known secret values from a string before it is stored or
	 * displayed anywhere (import log, admin notices, debug.log).
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	public static function redact( $message ) {
		$message = (string) $message;
		foreach ( self::get_secrets() as $secret ) {
			if ( '' === $secret ) {
				continue;
			}
			$message = str_replace( $secret, '••••••••', $message );
		}
		return $message;
	}

	/**
	 * Writes to the PHP error log (only when WP_DEBUG_LOG is enabled),
	 * always redacted.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	public static function debug( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Healthcare Jobs] ' . self::redact( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Starts a new import log row.
	 *
	 * @param string $trigger_type 'manual' or 'cron'.
	 * @return int Insert ID of the log row.
	 */
	public static function start_import( $trigger_type = 'manual' ) {
		global $wpdb;

		$table = Healthcare_Jobs_Database::import_log_table();

		$wpdb->insert(
			$table,
			array(
				'started_at'   => current_time( 'mysql', true ),
				'status'       => 'running',
				'trigger_type' => in_array( $trigger_type, array( 'manual', 'cron' ), true ) ? $trigger_type : 'manual',
			),
			array( '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Finalises an import log row with results.
	 *
	 * @param int    $log_id Log row ID.
	 * @param array  $stats  Keys: jobs_found, jobs_imported, jobs_updated, jobs_skipped, jobs_expired.
	 * @param string $status 'success', 'partial', or 'failed'.
	 * @param array  $errors List of error strings (already redacted here).
	 * @return void
	 */
	public static function finish_import( $log_id, array $stats, $status, array $errors = array() ) {
		global $wpdb;

		$table = Healthcare_Jobs_Database::import_log_table();

		$safe_errors = array_map( array( __CLASS__, 'redact' ), $errors );

		$wpdb->update(
			$table,
			array(
				'finished_at'   => current_time( 'mysql', true ),
				'status'        => in_array( $status, array( 'success', 'partial', 'failed' ), true ) ? $status : 'failed',
				'jobs_found'    => isset( $stats['jobs_found'] ) ? (int) $stats['jobs_found'] : 0,
				'jobs_imported' => isset( $stats['jobs_imported'] ) ? (int) $stats['jobs_imported'] : 0,
				'jobs_updated'  => isset( $stats['jobs_updated'] ) ? (int) $stats['jobs_updated'] : 0,
				'jobs_skipped'  => isset( $stats['jobs_skipped'] ) ? (int) $stats['jobs_skipped'] : 0,
				'jobs_expired'  => isset( $stats['jobs_expired'] ) ? (int) $stats['jobs_expired'] : 0,
				'errors'        => ! empty( $safe_errors ) ? wp_json_encode( array_values( $safe_errors ) ) : null,
			),
			array( 'id' => (int) $log_id ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fetches recent import log rows for the admin history screen.
	 *
	 * @param int $limit  Number of rows.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function get_history( $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table = Healthcare_Jobs_Database::import_log_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY started_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Returns the most recent import log row, or null.
	 *
	 * @return array|null
	 */
	public static function get_last_import() {
		global $wpdb;

		$table = Healthcare_Jobs_Database::import_log_table();

		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY started_at DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $row ? $row : null;
	}

	/**
	 * Counts total log rows, for pagination.
	 *
	 * @return int
	 */
	public static function count_history() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::import_log_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
