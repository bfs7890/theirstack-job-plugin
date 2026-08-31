<?php
/**
 * Job record CRUD, deduplication, and expiration.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Jobs {

	const STATUS_ACTIVE  = 'active';
	const STATUS_EXPIRED = 'expired';
	const STATUS_CLOSED  = 'closed';
	const STATUS_DRAFT   = 'draft';

	/**
	 * Columns that are safe to bulk-assign from normalised import data.
	 *
	 * @var string[]
	 */
	private static function job_columns() {
		return array(
			'external_job_id', 'source', 'job_source_type', 'source_url', 'title',
			'company_id', 'company_name', 'company_website', 'description',
			'requirements', 'benefits', 'location', 'city', 'region', 'postcode',
			'country', 'country_code', 'employment_type', 'remote_type',
			'salary_min', 'salary_max', 'salary_currency', 'category', 'specialty',
			'seniority', 'employer_type', 'posted_at', 'closing_date', 'is_closed',
			'status', 'raw_data',
		);
	}

	/**
	 * Finds an existing job by its TheirStack external ID.
	 *
	 * @param string $external_job_id External job ID.
	 * @return array|null
	 */
	public static function find_by_external_id( $external_job_id ) {
		if ( empty( $external_job_id ) ) {
			return null;
		}
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE external_job_id = %s", $external_job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Inserts a new job or updates the existing one with the same
	 * external_job_id, preventing duplicate records.
	 *
	 * @param array $data Normalised job fields (see job_columns()).
	 * @return array { id: int, is_new: bool }
	 */
	public static function upsert( array $data ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$now   = current_time( 'mysql', true );

		$existing = self::find_by_external_id( $data['external_job_id'] );

		$fields = array();
		foreach ( self::job_columns() as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$fields[ $column ] = $data[ $column ];
			}
		}

		$fields['last_updated_at'] = $now;
		$fields['updated_at']      = $now;

		if ( $existing ) {
			$fields['slug'] = $existing['slug'];
			$wpdb->update( $table, $fields, array( 'id' => (int) $existing['id'] ) );
			return array( 'id' => (int) $existing['id'], 'is_new' => false );
		}

		$fields['slug']          = self::generate_unique_slug( $data['title'] ?? '', $data['city'] ?? ( $data['location'] ?? '' ) );
		$fields['first_seen_at'] = $now;
		$fields['created_at']    = $now;

		$wpdb->insert( $table, $fields );
		$new_id = (int) $wpdb->insert_id;

		// The slug above was built without knowing the row ID; make it
		// permanently unique by suffixing the ID if a collision remains.
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$dupe  = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id != %d", $fields['slug'], $new_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $dupe ) {
			$wpdb->update( $table, array( 'slug' => $fields['slug'] . '-' . $new_id ), array( 'id' => $new_id ) );
		}

		return array( 'id' => $new_id, 'is_new' => true );
	}

	/**
	 * Builds a unique, URL-safe slug from a job title and location.
	 *
	 * @param string $title Job title.
	 * @param string $place City or location string.
	 * @return string
	 */
	public static function generate_unique_slug( $title, $place = '' ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$base = trim( $title . ( $place ? ' ' . $place : '' ) );
		$base = sanitize_title( $base );
		if ( '' === $base ) {
			$base = 'healthcare-job';
		}
		$base = substr( $base, 0, 180 );

		$slug  = $base;
		$index = 2;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slug = $base . '-' . $index;
			++$index;
		}

		return $slug;
	}

	/**
	 * Fetches one job by ID.
	 *
	 * @param int $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Fetches one published, active job by its public slug.
	 *
	 * @param string $slug Job slug.
	 * @return array|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $slug ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Marks a single job's status (deactivate, close, reactivate) from the
	 * admin jobs screen. Capability checks happen in the caller.
	 *
	 * @param int    $id     Job ID.
	 * @param string $status One of the STATUS_* constants.
	 * @return void
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$valid = array( self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_CLOSED, self::STATUS_DRAFT );
		if ( ! in_array( $status, $valid, true ) ) {
			return;
		}
		$wpdb->update(
			$table,
			array(
				'status'     => $status,
				'is_closed'  => in_array( $status, array( self::STATUS_CLOSED, self::STATUS_EXPIRED ), true ) ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Permanently deletes a job row (admin-triggered only; imports never
	 * delete, they only change status, so history is preserved unless an
	 * administrator explicitly deletes a record).
	 *
	 * @param int $id Job ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Marks jobs older than the configured maximum age as expired. Called
	 * at the end of every import run.
	 *
	 * @param int $max_age_days Maximum job age in days.
	 * @return int Number of rows expired.
	 */
	public static function expire_by_max_age( $max_age_days ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( absint( $max_age_days ) * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND posted_at IS NOT NULL AND posted_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_EXPIRED,
				current_time( 'mysql', true ),
				self::STATUS_ACTIVE,
				$cutoff
			)
		);
	}

	/**
	 * Marks jobs that TheirStack reported as closed for this run.
	 *
	 * @param string[] $external_job_ids External IDs reported closed.
	 * @return int Number of rows updated.
	 */
	public static function mark_closed_by_external_ids( array $external_job_ids ) {
		if ( empty( $external_job_ids ) ) {
			return 0;
		}
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$placeholders = implode( ',', array_fill( 0, count( $external_job_ids ), '%s' ) );
		$sql          = "UPDATE {$table} SET status = %s, is_closed = 1, updated_at = %s WHERE external_job_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params = array_merge( array( self::STATUS_CLOSED, current_time( 'mysql', true ) ), $external_job_ids );

		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Marks jobs as expired when they have not appeared in any import for
	 * longer than the staleness window, i.e. the source likely removed
	 * them. Scoped by job_source_type='aggregated' so direct employer jobs
	 * (future phase) are never touched by this pass.
	 *
	 * @param int $stale_days Days without being re-seen before expiring.
	 * @return int Number of rows expired.
	 */
	public static function expire_unseen( $stale_days ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( absint( $stale_days ) * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND job_source_type = 'aggregated' AND last_updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_EXPIRED,
				current_time( 'mysql', true ),
				self::STATUS_ACTIVE,
				$cutoff
			)
		);
	}

	/**
	 * Dashboard/admin counters.
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$week_start  = gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days' ) );

		return array(
			'active_jobs'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_ACTIVE ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'new_today'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE first_seen_at >= %s", $today_start ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'new_this_week'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE first_seen_at >= %s", $week_start ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'expired_jobs'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_EXPIRED ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'total_jobs'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Simple admin listing query (search + status filter), separate from
	 * the richer public-facing Healthcare_Jobs_Search class.
	 *
	 * @param array $args {
	 *     @type string $search
	 *     @type string $status
	 *     @type int    $paged
	 *     @type int    $per_page
	 * }
	 * @return array { items: array, total: int }
	 */
	public static function admin_query( array $args ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(title LIKE %s OR company_name LIKE %s OR location LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = sanitize_text_field( $args['category'] );
		}

		$where_sql = implode( ' AND ', $where );

		$per_page = ! empty( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$paged    = ! empty( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = empty( $params )
			? (int) $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$list_sql   = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY posted_at DESC, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items      = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}
}
