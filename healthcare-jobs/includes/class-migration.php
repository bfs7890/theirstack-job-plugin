<?php
/**
 * One-time migration of pre-Directorist jobs into Directorist listings.
 *
 * Before version 2.0.0, wp_healthcare_jobs was this plugin's own
 * authoritative job store (title, description, company_name, location,
 * etc. columns). From 2.0.0 onward that table holds only TheirStack<->
 * Directorist sync metadata, and dbDelta() never drops the old columns -
 * they remain physically present and populated on any site upgraded from
 * an earlier version, which is exactly what this class reads from to
 * create the equivalent Directorist listings, once, under explicit admin
 * control.
 *
 * Nothing here ever deletes the legacy rows or their data; a row is only
 * ever marked as migrated by having `directorist_post_id` set on it.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Migration {

	/**
	 * Whether this table still has the pre-2.0.0 columns at all. False on
	 * any fresh 2.0.0+ install, which never had them and has nothing to
	 * migrate.
	 *
	 * @return bool
	 */
	private static function has_legacy_columns() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'title' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Counts legacy rows that have not yet been migrated (no Directorist
	 * post linked). Used to show/hide the "Migrate Existing Jobs" prompt.
	 *
	 * @return int
	 */
	public static function count_pending() {
		if ( ! self::has_legacy_columns() ) {
			return 0;
		}
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE directorist_post_id IS NULL AND external_job_id != '' AND title IS NOT NULL AND title != ''" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Runs the migration: every pending legacy row is classified and
	 * synced into Directorist exactly like a live TheirStack import would,
	 * so the same category rules apply consistently to old and new jobs.
	 *
	 * @return array Result summary, keyed like Healthcare_Jobs_Importer::run().
	 */
	public static function run() {
		$stats  = array(
			'jobs_found'   => 0,
			'jobs_created' => 0,
			'jobs_updated' => 0,
			'jobs_skipped' => 0,
			'jobs_closed'  => 0,
			'jobs_failed'  => 0,
		);
		$errors = array();

		if ( ! self::has_legacy_columns() ) {
			return array( 'stats' => $stats, 'status' => 'success', 'errors' => array(), 'message' => __( 'Nothing to migrate - this install has no pre-Directorist job data.', 'healthcare-jobs' ) );
		}

		$log_id = Healthcare_Jobs_Logger::start_import( 'manual' );

		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE directorist_post_id IS NULL AND external_job_id != '' AND title IS NOT NULL AND title != ''", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$stats['jobs_found'] = count( $rows );

		foreach ( (array) $rows as $row ) {
			self::migrate_row( $row, $stats, $errors );
		}

		$status = 'success';
		if ( ! empty( $errors ) ) {
			$status = ( $stats['jobs_created'] > 0 || $stats['jobs_updated'] > 0 ) ? 'partial' : 'failed';
		}

		Healthcare_Jobs_Logger::finish_import(
			$log_id,
			array(
				'jobs_found'    => $stats['jobs_found'],
				'jobs_imported' => $stats['jobs_created'],
				'jobs_updated'  => $stats['jobs_updated'],
				'jobs_skipped'  => $stats['jobs_skipped'],
				'jobs_expired'  => $stats['jobs_closed'],
			),
			$status,
			$errors
		);

		return array( 'stats' => $stats, 'status' => $status, 'errors' => $errors );
	}

	/**
	 * Migrates a single legacy row.
	 *
	 * @param array $row    Legacy wp_healthcare_jobs row (old columns).
	 * @param array $stats  Reference to running stats.
	 * @param array $errors Reference to running errors.
	 * @return void
	 */
	private static function migrate_row( array $row, array &$stats, array &$errors ) {
		$title = trim( (string) ( $row['title'] ?? '' ) );

		// Re-classify from scratch rather than trusting the legacy
		// free-text `category` column - that column is exactly the source
		// of the false-positive classifications this migration exists to
		// correct (e.g. a legacy row may already say "Doctors" for an IT
		// Consultant role).
		$classification = Healthcare_Jobs_Classifier::classify( $title, (string) ( $row['description'] ?? '' ) );

		if ( 0 === $classification['term_id'] ) {
			++$stats['jobs_skipped'];
			$errors[] = sprintf(
				/* translators: %s: job title */
				__( '[migration] Skipped "%s" - does not match a configured healthcare category under the corrected classifier.', 'healthcare-jobs' ),
				$title
			);
			return;
		}

		$is_closed = ! empty( $row['is_closed'] ) || in_array( $row['status'] ?? '', array( 'closed', 'expired' ), true );

		$job_data = array(
			'external_job_id'  => (string) $row['external_job_id'],
			'source'           => (string) ( $row['source'] ?? 'theirstack' ),
			'source_url'       => $row['source_url'] ?? '',
			'title'            => $title,
			'description'      => (string) ( $row['description'] ?? '' ),
			'company_name'     => (string) ( $row['company_name'] ?? '' ),
			'company_website'  => (string) ( $row['company_website'] ?? '' ),
			'company_logo_url' => '',
			'location'         => (string) ( $row['location'] ?? '' ),
			'city'             => (string) ( $row['city'] ?? '' ),
			'region'           => (string) ( $row['region'] ?? '' ),
			'postcode'         => (string) ( $row['postcode'] ?? '' ),
			'country'          => (string) ( $row['country'] ?? '' ),
			'country_code'     => (string) ( $row['country_code'] ?? '' ),
			'employment_type'  => (string) ( $row['employment_type'] ?? '' ),
			'remote_type'      => (string) ( $row['remote_type'] ?? '' ),
			'salary_min'       => isset( $row['salary_min'] ) && is_numeric( $row['salary_min'] ) ? (int) $row['salary_min'] : null,
			'salary_max'       => isset( $row['salary_max'] ) && is_numeric( $row['salary_max'] ) ? (int) $row['salary_max'] : null,
			'salary_currency'  => $row['salary_currency'] ?? null,
			'posted_at'        => $row['posted_at'] ?? null,
			'closing_date'     => $row['closing_date'] ?? null,
			'is_closed'        => $is_closed,
			'raw_data'         => ! empty( $row['raw_data'] ) ? json_decode( $row['raw_data'], true ) : array(),
		);

		$result = Healthcare_Jobs_Directorist_Sync::sync( $job_data, $classification['term_id'] );

		if ( $result['error'] ) {
			++$stats['jobs_failed'];
			$errors[] = sprintf(
				/* translators: 1: job title, 2: error message */
				__( '[migration] Failed to migrate "%1$s": %2$s', 'healthcare-jobs' ),
				$title,
				$result['error']
			);
			return;
		}

		if ( $is_closed ) {
			++$stats['jobs_closed'];
		} elseif ( $result['is_new'] ) {
			++$stats['jobs_created'];
		} else {
			++$stats['jobs_updated'];
		}
	}
}
