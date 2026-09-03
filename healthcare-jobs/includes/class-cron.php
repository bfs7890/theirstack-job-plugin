<?php
/**
 * WP-Cron scheduling for automatic imports.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Cron {

	const HOOK = 'healthcare_jobs_run_import';

	/**
	 * Registers the custom cron intervals and the import hook. Called on
	 * every request via `init` (cheap: just filter/action registration).
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled_import' ) );
	}

	/**
	 * Available import frequencies, shared between the cron_schedules
	 * filter and the Settings screen dropdown.
	 *
	 * @return array<string, array{interval:int,label:string}>
	 */
	public static function get_schedule_choices() {
		return array(
			'hourly'      => array(
				'interval' => HOUR_IN_SECONDS,
				'label'    => __( 'Every hour', 'healthcare-jobs' ),
			),
			'three_hours' => array(
				'interval' => 3 * HOUR_IN_SECONDS,
				'label'    => __( 'Every 3 hours', 'healthcare-jobs' ),
			),
			'six_hours'   => array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'label'    => __( 'Every 6 hours (recommended)', 'healthcare-jobs' ),
			),
			'twelve_hours' => array(
				'interval' => 12 * HOUR_IN_SECONDS,
				'label'    => __( 'Every 12 hours', 'healthcare-jobs' ),
			),
			'daily'       => array(
				'interval' => DAY_IN_SECONDS,
				'label'    => __( 'Once daily', 'healthcare-jobs' ),
			),
		);
	}

	/**
	 * Registers our custom intervals with WP-Cron.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		foreach ( self::get_schedule_choices() as $key => $data ) {
			$schedules[ 'healthcare_jobs_' . $key ] = array(
				'interval' => $data['interval'],
				'display'  => $data['label'],
			);
		}
		return $schedules;
	}

	/**
	 * (Re)schedules the import event to match current settings. Safe to
	 * call any time settings are saved; it always clears any previous
	 * schedule first so frequency changes take effect immediately.
	 *
	 * @return void
	 */
	public static function reschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}

		$settings = Healthcare_Jobs_Settings::get_all();

		if ( empty( $settings['auto_import_enabled'] ) ) {
			return;
		}

		$frequency = ! empty( $settings['import_frequency'] ) ? $settings['import_frequency'] : 'six_hours';
		if ( ! array_key_exists( $frequency, self::get_schedule_choices() ) ) {
			$frequency = 'six_hours';
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'healthcare_jobs_' . $frequency, self::HOOK );
	}

	/**
	 * Clears all scheduled events for this plugin (deactivation).
	 *
	 * @return void
	 */
	public static function clear() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * The actual cron callback. Delegates to the importer, which itself
	 * takes out a lock so a slow-running import is never doubled up.
	 *
	 * @return void
	 */
	public static function run_scheduled_import() {
		$importer = new Healthcare_Jobs_Importer();
		$importer->run( 'cron' );

		if ( Healthcare_Jobs_Settings::adzuna_import_enabled() ) {
			$adzuna_importer = new Healthcare_Jobs_Adzuna_Importer();
			$adzuna_importer->run( 'cron' );
		}
	}

	/**
	 * Human-readable "next run" for the dashboard.
	 *
	 * @return int|false Unix timestamp, or false if nothing scheduled.
	 */
	public static function get_next_run() {
		return wp_next_scheduled( self::HOOK );
	}
}
