<?php
/**
 * Admin-facing helpers for the Directorist "Jobs" listings this plugin
 * imports/syncs.
 *
 * Directorist (post type `at_biz_dir`) is the authoritative store for
 * listing content - this class only reads from and updates real
 * Directorist posts (via wp_update_post/wp_delete_post), it never
 * maintains a parallel copy of job data. It exists to power the admin
 * Dashboard stats and the admin Jobs list/row actions.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Jobs {

	const STATUS_ACTIVE = 'publish';

	/**
	 * Dashboard/admin counters, scoped to the "Jobs" listing type only.
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wpdb;

		$job_term_id = Healthcare_Jobs_Directorist_Mapper::get_job_listing_type_term_id();
		$closed_status = Healthcare_Jobs_Directorist_Mapper::get_closed_post_status();

		$base_join = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = %s AND tt.term_id = %d AND p.post_type = 'at_biz_dir'";

		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$week_start  = gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days' ) );

		$active_jobs = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$base_join} AND p.post_status = 'publish'", self::LISTING_TAXONOMY(), $job_term_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$expired_jobs = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$base_join} AND p.post_status = %s", self::LISTING_TAXONOMY(), $job_term_id, $closed_status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$total_jobs = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$base_join}", self::LISTING_TAXONOMY(), $job_term_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$new_today = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$base_join} AND p.post_date_gmt >= %s", self::LISTING_TAXONOMY(), $job_term_id, $today_start ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$new_this_week = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$base_join} AND p.post_date_gmt >= %s", self::LISTING_TAXONOMY(), $job_term_id, $week_start ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Distinct employer names among Job listings, read live from
		// Directorist postmeta - there is no separate company entity on this
		// install, and nothing populates wp_healthcare_companies any more
		// now that Directorist is the authoritative store.
		$company_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_custom-text' AND pm.meta_value != ''
				WHERE tt.taxonomy = %s AND tt.term_id = %d AND p.post_type = 'at_biz_dir' AND p.post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::LISTING_TAXONOMY(),
				$job_term_id
			)
		);

		return array(
			'active_jobs'   => $active_jobs,
			'new_today'     => $new_today,
			'new_this_week' => $new_this_week,
			'expired_jobs'  => $expired_jobs,
			'total_jobs'    => $total_jobs,
			'company_count' => $company_count,
		);
	}

	/**
	 * The taxonomy Directorist uses for listing types.
	 *
	 * @return string
	 */
	private static function LISTING_TAXONOMY() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return Healthcare_Jobs_Search::LISTING_TYPE_TAXONOMY;
	}

	/**
	 * Admin Jobs list query: search + status/category filter + pagination,
	 * scoped to the "Jobs" listing type, across every post_status (unlike
	 * the public search, which only ever shows published listings).
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

		$job_term_id = Healthcare_Jobs_Directorist_Mapper::get_job_listing_type_term_id();

		$joins        = array(
			"INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID",
			"INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s AND tt.term_id = %d",
		);
		$join_params  = array( self::LISTING_TAXONOMY(), $job_term_id );
		$where        = array( "p.post_type = 'at_biz_dir'", "p.post_status != 'trash'" );
		$where_params = array();

		if ( ! empty( $args['search'] ) ) {
			$joins[]        = "LEFT JOIN {$wpdb->postmeta} pm_company ON pm_company.post_id = p.ID AND pm_company.meta_key = '_custom-text'";
			$like           = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]        = '(p.post_title LIKE %s OR pm_company.meta_value LIKE %s)';
			$where_params[] = $like;
			$where_params[] = $like;
		}

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			// The admin filter dropdown speaks in "active"/"closed"/"expired"
			// regardless of what Directorist's actual post_status value is on
			// this install (its custom 'expired' status, or a 'draft'
			// fallback) - translate rather than querying the raw input value
			// directly, since e.g. "active" is never itself a post_status.
			$status_key = sanitize_key( $args['status'] );
			if ( 'active' === $status_key ) {
				$where[] = "p.post_status = 'publish'";
			} elseif ( in_array( $status_key, array( 'closed', 'expired' ), true ) ) {
				$where[]        = 'p.post_status = %s';
				$where_params[] = Healthcare_Jobs_Directorist_Mapper::get_closed_post_status();
			} else {
				$where[]        = 'p.post_status = %s';
				$where_params[] = $status_key;
			}
		}

		if ( ! empty( $args['category'] ) ) {
			$term = get_term_by( is_numeric( $args['category'] ) ? 'id' : 'name', $args['category'], Healthcare_Jobs_Categories::TAXONOMY );
			if ( $term ) {
				$joins[]       = "INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID";
				$joins[]       = "INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = %s AND tt_cat.term_id = %d";
				$join_params[] = Healthcare_Jobs_Categories::TAXONOMY;
				$join_params[] = (int) $term->term_id;
			}
		}

		$joins_sql = implode( "\n", $joins );
		$where_sql = implode( ' AND ', $where );
		$params    = array_merge( $join_params, $where_params );

		$per_page = ! empty( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$paged    = ! empty( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( 0 === $total ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$list_sql    = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql} ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array();
		$ids   = wp_list_pluck( (array) $rows, 'ID' );
		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', $ids );
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post ) {
					$items[] = self::admin_row( $post );
				}
			}
		}

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Builds one admin-list row from a Directorist post.
	 *
	 * @param WP_Post $post Listing post.
	 * @return array
	 */
	private static function admin_row( $post ) {
		$category_terms = get_the_terms( $post->ID, Healthcare_Jobs_Categories::TAXONOMY );

		return array(
			'id'           => $post->ID,
			'title'        => get_the_title( $post ),
			'company_name' => get_post_meta( $post->ID, '_custom-text', true ),
			'location'     => wp_get_object_terms( $post->ID, Healthcare_Jobs_Search::LOCATION_TAXONOMY, array( 'fields' => 'names' ) )[0] ?? '',
			'category'     => ! empty( $category_terms ) && ! is_wp_error( $category_terms ) ? $category_terms[0]->name : '',
			'posted_at'    => $post->post_date_gmt,
			'status'       => $post->post_status,
			'source'       => get_post_meta( $post->ID, 'healthcare_jobs_source', true ),
			'source_url'   => get_post_meta( $post->ID, '_djobs-apply-now', true ),
			'permalink'    => get_permalink( $post ),
		);
	}

	/**
	 * Changes a listing's post_status (Deactivate/Activate row actions).
	 *
	 * @param int    $id     Directorist post ID.
	 * @param string $status 'publish' (active) or the configured closed status.
	 * @return void
	 */
	public static function set_status( $id, $status ) {
		$valid = array( self::STATUS_ACTIVE, Healthcare_Jobs_Directorist_Mapper::get_closed_post_status() );
		if ( ! in_array( $status, $valid, true ) ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Permanently deletes a Directorist listing (admin-triggered only).
	 *
	 * @param int $id Directorist post ID.
	 * @return void
	 */
	public static function delete( $id ) {
		wp_delete_post( (int) $id, true );
	}
}
