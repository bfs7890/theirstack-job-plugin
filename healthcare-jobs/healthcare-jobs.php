<?php
/**
 * Plugin Name:       Healthcare Jobs Aggregator
 * Plugin URI:        https://example.com/healthcare-jobs
 * Description:       Imports UK healthcare vacancies from the TheirStack Jobs API into a local database and displays them as a searchable job board via the [healthcare_jobs] shortcode.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Healthcare Jobs Aggregator
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       healthcare-jobs
 * Domain Path:       /languages
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

define( 'HEALTHCARE_JOBS_VERSION', '1.0.0' );
define( 'HEALTHCARE_JOBS_PLUGIN_FILE', __FILE__ );
define( 'HEALTHCARE_JOBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEALTHCARE_JOBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HEALTHCARE_JOBS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Loads every plugin class. Kept as simple explicit requires (no
 * autoloader/Composer dependency) so the plugin has zero build step and
 * works the moment it is uploaded to wp-content/plugins.
 *
 * @return void
 */
function healthcare_jobs_load_includes() {
	$includes = array(
		'includes/class-logger.php',
		'includes/class-database.php',
		'includes/class-settings.php',
		'includes/class-theirstack-api.php',
		'includes/class-categories.php',
		'includes/class-companies.php',
		'includes/class-jobs.php',
		'includes/class-search.php',
		'includes/class-importer.php',
		'includes/class-cron.php',
		'includes/class-seo.php',
		'includes/class-shortcode.php',
		'includes/class-block.php',
	);

	foreach ( $includes as $file ) {
		require_once HEALTHCARE_JOBS_PLUGIN_DIR . $file;
	}

	if ( is_admin() ) {
		require_once HEALTHCARE_JOBS_PLUGIN_DIR . 'includes/class-admin.php';
	}
}
healthcare_jobs_load_includes();

/**
 * Activation: create tables, seed defaults, grant the capability to
 * administrators, schedule cron, and queue a one-time rewrite flush.
 * Nothing here touches any existing theme, plugin, post, or option outside
 * this plugin's own namespace.
 *
 * @return void
 */
function healthcare_jobs_activate() {
	Healthcare_Jobs_Database::install();

	$role = get_role( 'administrator' );
	if ( $role && ! $role->has_cap( Healthcare_Jobs_Settings::CAPABILITY ) ) {
		$role->add_cap( Healthcare_Jobs_Settings::CAPABILITY );
	}

	Healthcare_Jobs_Cron::reschedule();

	update_option( 'healthcare_jobs_flush_rewrites', 1 );
}
register_activation_hook( __FILE__, 'healthcare_jobs_activate' );

/**
 * Deactivation: stop scheduled imports and clean up rewrite rules. Data
 * (jobs, companies, settings) is left untouched — deactivating is not
 * uninstalling.
 *
 * @return void
 */
function healthcare_jobs_deactivate() {
	Healthcare_Jobs_Cron::clear();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'healthcare_jobs_deactivate' );

/**
 * Loads the translation files and applies any pending database schema
 * upgrade. Runs on every request via plugins_loaded, before init.
 *
 * @return void
 */
function healthcare_jobs_bootstrap() {
	load_plugin_textdomain( 'healthcare-jobs', false, dirname( HEALTHCARE_JOBS_PLUGIN_BASENAME ) . '/languages' );
	Healthcare_Jobs_Database::maybe_upgrade();
	Healthcare_Jobs_Settings::maybe_migrate_api_key_encryption();
}
add_action( 'plugins_loaded', 'healthcare_jobs_bootstrap' );

/**
 * Registers everything that needs the full WordPress query/rewrite API.
 *
 * @return void
 */
function healthcare_jobs_init() {
	Healthcare_Jobs_Cron::init();
	Healthcare_Jobs_SEO::register_rewrites();
	Healthcare_Jobs_Shortcode::init();
	Healthcare_Jobs_Block::init();

	if ( is_admin() && class_exists( 'Healthcare_Jobs_Admin' ) ) {
		Healthcare_Jobs_Admin::init();
	}
}
add_action( 'init', 'healthcare_jobs_init' );

add_filter( 'query_vars', array( 'Healthcare_Jobs_SEO', 'register_query_vars' ) );
add_filter( 'template_include', array( 'Healthcare_Jobs_SEO', 'maybe_load_job_template' ) );
add_filter( 'wp_robots', array( 'Healthcare_Jobs_SEO', 'filter_robots' ) );
add_action( 'wp_head', array( 'Healthcare_Jobs_SEO', 'output_canonical' ) );
add_action( 'wp_head', array( 'Healthcare_Jobs_SEO', 'output_structured_data' ) );
