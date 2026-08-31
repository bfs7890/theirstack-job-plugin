<?php
/**
 * PHPUnit bootstrap for the Healthcare Jobs Aggregator plugin.
 *
 * Requires the standard WordPress PHPUnit test scaffolding. Run
 * bin/install-wp-tests.sh first (see README.md > Testing).
 *
 * @package HealthcareJobs
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	fwrite( STDERR, "Could not find {$_tests_dir}/includes/functions.php. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Loads the plugin under test.
 *
 * @return void
 */
function _healthcare_jobs_manually_load_plugin() {
	require dirname( __DIR__ ) . '/healthcare-jobs.php';
}
tests_add_filter( 'muplugins_loaded', '_healthcare_jobs_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
