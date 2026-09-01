<?php
/**
 * Writes mapped job data into Directorist as real `at_biz_dir` listings.
 *
 * Directorist is the authoritative store for listing content. This class
 * finds an existing listing by the TheirStack external job ID (stored as
 * postmeta, checked first so a lookup never depends on our own tracking
 * table being in sync), creates or updates it via the same core WordPress
 * functions Directorist's own submission form ultimately calls
 * (wp_insert_post/wp_update_post, update_post_meta, wp_set_object_terms),
 * and explicitly fires the `atbdp_listing_inserted`/`atbdp_listing_updated`
 * hooks Directorist's own class-add-listing.php fires after a normal
 * submission - so any internal cache or search-index population tied to
 * those hooks still runs even though we bypass the submission form itself.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Directorist_Sync {

	/**
	 * Creates or updates a Directorist listing for one normalised job.
	 *
	 * @param array $job              Normalised job data.
	 * @param int   $category_term_id Directorist category term ID.
	 * @return array{post_id:int, is_new:bool, error:string|null}
	 */
	public static function sync( array $job, $category_term_id ) {
		$external_id = sanitize_text_field( (string) ( $job['external_job_id'] ?? '' ) );
		if ( '' === $external_id ) {
			return array( 'post_id' => 0, 'is_new' => false, 'error' => __( 'Missing external job ID.', 'healthcare-jobs' ) );
		}

		$mapped = Healthcare_Jobs_Directorist_Mapper::map( $job, $category_term_id );

		$existing_post_id = self::find_existing_post_id( $external_id );
		$is_new           = ! $existing_post_id;

		$postarr = $mapped['post'];
		if ( $is_new ) {
			$postarr['post_author'] = self::get_import_author_id();
			$post_id                = wp_insert_post( wp_slash( $postarr ), true );
		} else {
			$postarr['ID'] = $existing_post_id;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$message = is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'Unknown error creating the Directorist listing.', 'healthcare-jobs' );
			return array( 'post_id' => 0, 'is_new' => false, 'error' => $message );
		}

		foreach ( $mapped['meta'] as $meta_key => $meta_value ) {
			update_post_meta( $post_id, $meta_key, $meta_value );
		}

		// Dual-write the listing type: as postmeta (the mechanism confirmed
		// on this install's existing data) and as a taxonomy term
		// assignment (the taxonomy is registered on at_biz_dir, and some
		// Directorist code paths may query by term rather than meta) - both
		// are cheap and harmless to set together.
		wp_set_object_terms( $post_id, array( Healthcare_Jobs_Directorist_Mapper::get_job_listing_type_term_id() ), 'atbdp_listing_types' );

		if ( $mapped['category_term_id'] > 0 ) {
			wp_set_object_terms( $post_id, array( $mapped['category_term_id'] ), Healthcare_Jobs_Categories::TAXONOMY );
		}

		if ( $mapped['location_term_id'] > 0 ) {
			wp_set_object_terms( $post_id, array( $mapped['location_term_id'] ), 'at_biz_dir-location' );
		}

		if ( $is_new && ! empty( $job['company_logo_url'] ) && ! has_post_thumbnail( $post_id ) ) {
			self::maybe_attach_logo( $post_id, $job['company_logo_url'] );
		}

		if ( $is_new ) {
			/**
			 * Fires after a TheirStack job is created as a Directorist
			 * listing, mirroring Directorist's own post-submission hook.
			 *
			 * @param int $post_id Listing post ID.
			 */
			do_action( 'atbdp_listing_inserted', $post_id );
		} else {
			/**
			 * Fires after a TheirStack job's existing Directorist listing
			 * is updated, mirroring Directorist's own post-edit hook.
			 *
			 * @param int   $post_id Listing post ID.
			 * @param array $postarr Updated post fields.
			 */
			do_action( 'atbdp_listing_updated', $post_id, $postarr );
		}

		self::update_sync_record( $external_id, $post_id, $mapped['category_term_id'], $job );

		return array( 'post_id' => (int) $post_id, 'is_new' => $is_new, 'error' => null );
	}

	/**
	 * Marks a listing closed: applies Directorist's own expiry status
	 * rather than deleting it, so historical records and URLs survive.
	 *
	 * @param int $post_id Listing post ID.
	 * @return void
	 */
	public static function mark_closed( $post_id ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => Healthcare_Jobs_Directorist_Mapper::get_closed_post_status(),
			)
		);
		update_post_meta( $post_id, '_expiry_date', current_time( 'mysql' ) );
	}

	/**
	 * Finds an existing Directorist listing by TheirStack external job ID.
	 * Queries postmeta directly (the source of truth) rather than only our
	 * own tracking table, so a lookup is correct even if that table is
	 * ever out of sync.
	 *
	 * @param string $external_id External job ID.
	 * @return int Post ID, or 0 if not found.
	 */
	public static function find_existing_post_id( $external_id ) {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Healthcare_Jobs_Directorist_Mapper::get_external_id_meta_key(),
				$external_id
			)
		);

		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * The WordPress user new listings are authored as. Defaults to the
	 * first administrator account; filterable for sites that prefer a
	 * dedicated "TheirStack Import" user.
	 *
	 * @return int
	 */
	private static function get_import_author_id() {
		$default = 0;

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);
		if ( ! empty( $admins ) ) {
			$default = (int) $admins[0];
		}

		/**
		 * Filters the WordPress user ID that imported Directorist listings
		 * are authored as.
		 *
		 * @param int $author_id Default author user ID.
		 */
		return (int) apply_filters( 'healthcare_jobs_directorist_import_author', $default );
	}

	/**
	 * Best-effort featured image from a company logo URL. Never fatal -
	 * logo download/attachment failures are swallowed and simply leave the
	 * listing without a featured image.
	 *
	 * @param int    $post_id  Listing post ID.
	 * @param string $logo_url Remote logo URL.
	 * @return void
	 */
	private static function maybe_attach_logo( $post_id, $logo_url ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( esc_url_raw( $logo_url ), $post_id, null, 'id' );

		if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		} else {
			Healthcare_Jobs_Logger::debug( 'Logo sideload failed for post ' . $post_id . ': ' . ( is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'unknown error' ) );
		}
	}

	/**
	 * Upserts our own lightweight sync-tracking row (external_job_id <->
	 * Directorist post_id), used for fast duplicate lookup in bulk imports
	 * and for the admin Import History audit trail. Never the source of
	 * truth for listing content - Directorist's own postmeta is.
	 *
	 * @param string $external_id      External job ID.
	 * @param int    $post_id          Directorist post ID.
	 * @param int    $category_term_id Assigned category term ID.
	 * @param array  $job              Normalised job data (for raw_data/source_url).
	 * @return void
	 */
	private static function update_sync_record( $external_id, $post_id, $category_term_id, array $job ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();
		$now   = current_time( 'mysql', true );

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE external_job_id = %s", $external_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$fields = array(
			'external_job_id'     => $external_id,
			'source'              => sanitize_text_field( (string) ( $job['source'] ?? 'theirstack' ) ),
			'source_url'          => ! empty( $job['source_url'] ) ? esc_url_raw( $job['source_url'] ) : null,
			'directorist_post_id' => (int) $post_id,
			'category_term_id'    => $category_term_id ? (int) $category_term_id : null,
			'sync_status'         => 'synced',
			'raw_data'            => wp_json_encode( $job['raw_data'] ?? array() ),
			'last_synced_at'      => $now,
			'updated_at'          => $now,
		);

		if ( $existing ) {
			$wpdb->update( $table, $fields, array( 'id' => (int) $existing ) );
			return;
		}

		$fields['first_seen_at'] = $now;
		$fields['created_at']    = $now;
		$wpdb->insert( $table, $fields );
	}
}
