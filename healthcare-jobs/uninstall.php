<?php
/**
 * Uninstall handler.
 *
 * Runs only when the plugin is deleted from the Plugins screen (never on
 * deactivation). By default this leaves all imported jobs, companies, and
 * settings in place — an administrator must explicitly tick "Delete Data
 * on Uninstall" in Settings first if they want a full removal.
 *
 * @package HealthcareJobs
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-database.php';
require_once __DIR__ . '/includes/class-settings.php';

// Always safe to clear: our own scheduled cron event and transient lock.
$cron_hook = 'healthcare_jobs_run_import';
$timestamp = wp_next_scheduled( $cron_hook );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, $cron_hook );
}
delete_transient( 'healthcare_jobs_import_lock' );

$settings = get_option( Healthcare_Jobs_Settings::OPTION_KEY, array() );
$delete_data = ! empty( $settings['delete_data_on_uninstall'] );

if ( $delete_data ) {
	Healthcare_Jobs_Database::drop_tables();

	delete_option( Healthcare_Jobs_Settings::OPTION_KEY );
	delete_option( 'healthcare_jobs_flush_rewrites' );

	$role = get_role( 'administrator' );
	if ( $role && $role->has_cap( Healthcare_Jobs_Settings::CAPABILITY ) ) {
		$role->remove_cap( Healthcare_Jobs_Settings::CAPABILITY );
	}
}
