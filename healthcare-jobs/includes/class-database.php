<?php
/**
 * Database schema management.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom database tables.
 *
 * All plugin data lives in dedicated tables (never in wp_posts) so that
 * imports of thousands of jobs do not bloat core WordPress tables.
 */
class Healthcare_Jobs_Database {

	/**
	 * Bump this whenever the schema changes so dbDelta() re-runs on upgrade.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	const OPTION_DB_VERSION = 'healthcare_jobs_db_version';

	/**
	 * Returns the fully qualified jobs table name.
	 *
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'healthcare_jobs';
	}

	/**
	 * Returns the fully qualified companies table name.
	 *
	 * @return string
	 */
	public static function companies_table() {
		global $wpdb;
		return $wpdb->prefix . 'healthcare_companies';
	}

	/**
	 * Returns the fully qualified import log table name.
	 *
	 * @return string
	 */
	public static function import_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'healthcare_import_log';
	}

	/**
	 * Returns the fully qualified categories table name.
	 *
	 * @return string
	 */
	public static function categories_table() {
		global $wpdb;
		return $wpdb->prefix . 'healthcare_categories';
	}

	/**
	 * Returns the fully qualified job titles table name.
	 *
	 * @return string
	 */
	public static function job_titles_table() {
		global $wpdb;
		return $wpdb->prefix . 'healthcare_job_titles';
	}

	/**
	 * Runs dbDelta() against all plugin tables. Safe to call on every
	 * plugin update, not only on activation.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$jobs_table       = self::jobs_table();
		$companies_table  = self::companies_table();
		$log_table        = self::import_log_table();
		$categories_table = self::categories_table();
		$titles_table     = self::job_titles_table();

		$sql = array();

		// Jobs table.
		$sql[] = "CREATE TABLE {$jobs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			external_job_id VARCHAR(191) NOT NULL DEFAULT '',
			source VARCHAR(64) NOT NULL DEFAULT 'theirstack',
			job_source_type VARCHAR(20) NOT NULL DEFAULT 'aggregated',
			source_url TEXT NULL,
			slug VARCHAR(220) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			company_id BIGINT UNSIGNED NULL,
			company_name VARCHAR(255) NOT NULL DEFAULT '',
			company_website VARCHAR(255) NULL,
			description LONGTEXT NULL,
			requirements LONGTEXT NULL,
			benefits LONGTEXT NULL,
			location VARCHAR(255) NULL,
			city VARCHAR(120) NULL,
			region VARCHAR(120) NULL,
			postcode VARCHAR(20) NULL,
			country VARCHAR(120) NULL,
			country_code VARCHAR(2) NULL,
			employment_type VARCHAR(60) NULL,
			remote_type VARCHAR(30) NULL,
			salary_min BIGINT NULL,
			salary_max BIGINT NULL,
			salary_currency VARCHAR(10) NULL,
			category VARCHAR(120) NULL,
			specialty VARCHAR(120) NULL,
			seniority VARCHAR(60) NULL,
			employer_type VARCHAR(60) NULL,
			posted_at DATETIME NULL,
			closing_date DATETIME NULL,
			is_closed TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			first_seen_at DATETIME NOT NULL,
			last_updated_at DATETIME NOT NULL,
			raw_data LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY external_job_id (external_job_id),
			UNIQUE KEY slug (slug),
			KEY title (title(191)),
			KEY company_name (company_name(191)),
			KEY location (location(191)),
			KEY country_code (country_code),
			KEY category (category),
			KEY posted_at (posted_at),
			KEY status (status),
			KEY company_id (company_id),
			KEY job_source_type (job_source_type),
			FULLTEXT KEY search_text (title,description,company_name)
		) {$charset_collate};";

		// Companies table.
		$sql[] = "CREATE TABLE {$companies_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			external_company_id VARCHAR(191) NOT NULL DEFAULT '',
			company_name VARCHAR(255) NOT NULL DEFAULT '',
			website VARCHAR(255) NULL,
			industry VARCHAR(120) NULL,
			employer_type VARCHAR(60) NULL,
			description LONGTEXT NULL,
			location VARCHAR(255) NULL,
			country VARCHAR(120) NULL,
			logo_url VARCHAR(500) NULL,
			active_jobs INT UNSIGNED NOT NULL DEFAULT 0,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY external_company_id (external_company_id),
			KEY company_name (company_name(191))
		) {$charset_collate};";

		// Import log table.
		$sql[] = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			jobs_found INT UNSIGNED NOT NULL DEFAULT 0,
			jobs_imported INT UNSIGNED NOT NULL DEFAULT 0,
			jobs_updated INT UNSIGNED NOT NULL DEFAULT 0,
			jobs_skipped INT UNSIGNED NOT NULL DEFAULT 0,
			jobs_expired INT UNSIGNED NOT NULL DEFAULT 0,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			errors LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at),
			KEY status (status)
		) {$charset_collate};";

		// Categories table (admin configurable).
		$sql[] = "CREATE TABLE {$categories_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			slug VARCHAR(120) NOT NULL,
			description TEXT NULL,
			menu_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		// Job titles table (admin configurable, mapped to a category).
		$sql[] = "CREATE TABLE {$titles_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			category_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY category_id (category_id),
			KEY title (title)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );

		self::maybe_seed_defaults();
	}

	/**
	 * Checks the stored schema version and re-runs install() if it is stale.
	 * Hooked to `plugins_loaded` so upgrades apply without reactivation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Seeds default healthcare categories and job titles on first install
	 * only. Never overwrites data an administrator has already edited.
	 *
	 * @return void
	 */
	private static function maybe_seed_defaults() {
		if ( get_option( 'healthcare_jobs_defaults_seeded' ) ) {
			return;
		}

		Healthcare_Jobs_Categories::seed_defaults();

		update_option( 'healthcare_jobs_defaults_seeded', 1 );
	}

	/**
	 * Drops all plugin tables. Only called from uninstall.php, and only
	 * when the administrator has opted in to full data removal.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			self::jobs_table(),
			self::companies_table(),
			self::import_log_table(),
			self::categories_table(),
			self::job_titles_table(),
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		delete_option( self::OPTION_DB_VERSION );
		delete_option( 'healthcare_jobs_defaults_seeded' );
	}
}
