<?php
/**
 * Public-facing job search and filtering.
 *
 * Queries Directorist listings (post type `at_biz_dir`, listing type
 * "Jobs") directly - never TheirStack, and never a separate job table of
 * this plugin's own. Directorist is the authoritative store; this class
 * only reads from it. Uses $wpdb->prepare() throughout and joins on
 * WordPress's own indexed columns (post_type/post_status, term
 * relationships) to stay fast as the listing count grows.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Search {

	const LOCATION_TAXONOMY = 'at_biz_dir-location';
	const LISTING_TYPE_TAXONOMY = 'atbdp_listing_types';

	/**
	 * Runs a filtered, paginated search against published Jobs listings.
	 *
	 * @param array $args {
	 *     @type string     $keyword          Free-text search (title/description/company).
	 *     @type string     $location         Location text match (matched against location term names).
	 *     @type string|int $category         Category name, slug, or term ID.
	 *     @type string|int $specialty        Same taxonomy as category (sub-terms act as specialties).
	 *     @type string     $employment_type  Exact match against _custom-select.
	 *     @type string     $remote_type      Exact match against _dirjob_job_type.
	 *     @type int        $salary_min       Minimum salary the job must offer.
	 *     @type string     $date_posted      One of: any, 24h, 7d, 14d, 30d.
	 *     @type int        $paged            1-indexed page number.
	 *     @type int        $per_page         Results per page.
	 * }
	 * @return array { items: array, total: int, pages: int }
	 */
	public static function query( array $args ) {
		global $wpdb;

		// join_params and where_params are kept strictly separate and only
		// merged at the very end (joins first, then wheres) because
		// $wpdb->prepare() substitutes %s/%d placeholders positionally,
		// left to right, against the FINAL concatenated SQL string - and
		// the JOIN clauses appear before the WHERE clause in that string
		// regardless of which filter added them. Interleaving a single
		// params array in filter-processing order would silently bind
		// values to the wrong placeholder whenever a later filter adds a
		// JOIN condition while an earlier one added a WHERE condition.
		$where        = array(
			"p.post_type = 'at_biz_dir'",
			"p.post_status = 'publish'",
		);
		$joins        = array();
		$join_params  = array();
		$where_params = array();

		// Restrict to the "Jobs" listing type only, so a shared Directorist
		// install with other directory types never leaks non-job listings
		// into this board.
		$joins[]         = "INNER JOIN {$wpdb->term_relationships} tr_type ON tr_type.object_id = p.ID";
		$joins[]         = "INNER JOIN {$wpdb->term_taxonomy} tt_type ON tt_type.term_taxonomy_id = tr_type.term_taxonomy_id AND tt_type.taxonomy = %s AND tt_type.term_id = %d";
		$join_params[]   = self::LISTING_TYPE_TAXONOMY;
		$join_params[]   = Healthcare_Jobs_Directorist_Mapper::get_job_listing_type_term_id();

		$keyword = isset( $args['keyword'] ) ? trim( wp_strip_all_tags( $args['keyword'] ) ) : '';
		if ( '' !== $keyword ) {
			$joins[]         = "LEFT JOIN {$wpdb->postmeta} pm_company ON pm_company.post_id = p.ID AND pm_company.meta_key = '_custom-text'";
			$like            = '%' . $wpdb->esc_like( $keyword ) . '%';
			$where[]         = '(p.post_title LIKE %s OR p.post_content LIKE %s OR pm_company.meta_value LIKE %s)';
			$where_params[]  = $like;
			$where_params[]  = $like;
			$where_params[]  = $like;
		}

		if ( ! empty( $args['location'] ) ) {
			$joins[]         = "INNER JOIN {$wpdb->term_relationships} tr_loc ON tr_loc.object_id = p.ID";
			$joins[]         = "INNER JOIN {$wpdb->term_taxonomy} tt_loc ON tt_loc.term_taxonomy_id = tr_loc.term_taxonomy_id AND tt_loc.taxonomy = %s";
			$joins[]         = "INNER JOIN {$wpdb->terms} t_loc ON t_loc.term_id = tt_loc.term_id";
			$join_params[]   = self::LOCATION_TAXONOMY;
			$where[]         = 't_loc.name LIKE %s';
			$where_params[]  = '%' . $wpdb->esc_like( trim( $args['location'] ) ) . '%';
		}

		foreach ( array( 'category', 'specialty' ) as $index => $field ) {
			if ( empty( $args[ $field ] ) ) {
				continue;
			}
			$term_id = self::resolve_category_term_id( $args[ $field ] );
			if ( ! $term_id ) {
				// An unrecognised category/specialty must not silently
				// return the unfiltered result set.
				return array( 'items' => array(), 'total' => 0, 'pages' => 0 );
			}
			$alias         = 'tr_cat' . $index;
			$tt_alias      = 'tt_cat' . $index;
			$joins[]       = "INNER JOIN {$wpdb->term_relationships} {$alias} ON {$alias}.object_id = p.ID";
			$joins[]       = "INNER JOIN {$wpdb->term_taxonomy} {$tt_alias} ON {$tt_alias}.term_taxonomy_id = {$alias}.term_taxonomy_id AND {$tt_alias}.taxonomy = %s AND {$tt_alias}.term_id = %d";
			$join_params[] = Healthcare_Jobs_Categories::TAXONOMY;
			$join_params[] = $term_id;
		}

		if ( ! empty( $args['employment_type'] ) ) {
			$joins[]       = "INNER JOIN {$wpdb->postmeta} pm_emp ON pm_emp.post_id = p.ID AND pm_emp.meta_key = '_custom-select' AND pm_emp.meta_value = %s";
			$join_params[] = sanitize_text_field( $args['employment_type'] );
		}

		if ( ! empty( $args['remote_type'] ) ) {
			$joins[]       = "INNER JOIN {$wpdb->postmeta} pm_remote ON pm_remote.post_id = p.ID AND pm_remote.meta_key = '_dirjob_job_type' AND pm_remote.meta_value = %s";
			$join_params[] = sanitize_text_field( $args['remote_type'] );
		}

		if ( ! empty( $args['salary_min'] ) ) {
			$joins[]       = "INNER JOIN {$wpdb->postmeta} pm_salary ON pm_salary.post_id = p.ID AND pm_salary.meta_key = '_custom-number' AND CAST(pm_salary.meta_value AS UNSIGNED) >= %d";
			$join_params[] = absint( $args['salary_min'] );
		}

		if ( ! empty( $args['date_posted'] ) && 'any' !== $args['date_posted'] ) {
			$days_map = array(
				'24h' => 1,
				'7d'  => 7,
				'14d' => 14,
				'30d' => 30,
			);
			if ( isset( $days_map[ $args['date_posted'] ] ) ) {
				$where[]        = 'p.post_date_gmt >= %s';
				$where_params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days_map[ $args['date_posted'] ] * DAY_IN_SECONDS ) );
			}
		}

		$joins_sql = implode( "\n", $joins );
		$where_sql = implode( ' AND ', $where );
		$params    = array_merge( $join_params, $where_params );

		$per_page = ! empty( $args['per_page'] ) ? min( 100, absint( $args['per_page'] ) ) : 20;
		$paged    = ! empty( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( 0 === $total ) {
			return array( 'items' => array(), 'total' => 0, 'pages' => 0 );
		}

		$list_sql    = "SELECT DISTINCT p.ID, p.post_date_gmt FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql} ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$post_ids = wp_list_pluck( (array) $rows, 'ID' );

		return array(
			'items' => self::hydrate( $post_ids ),
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Loads full display data for a page of listing IDs, priming the
	 * postmeta/term caches first so this is a small, fixed number of
	 * queries regardless of how many IDs are on the page.
	 *
	 * @param int[] $post_ids Listing post IDs, in display order.
	 * @return array
	 */
	private static function hydrate( array $post_ids ) {
		if ( empty( $post_ids ) ) {
			return array();
		}

		update_meta_cache( 'post', $post_ids );
		update_object_term_cache( $post_ids, 'at_biz_dir' );

		$items = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$items[] = self::build_card_data( $post );
		}
		return $items;
	}

	/**
	 * Builds the flat array job-card.php / job-detail.php expect, sourced
	 * entirely from the Directorist post + its postmeta/taxonomy terms.
	 *
	 * @param WP_Post $post Directorist listing post.
	 * @return array
	 */
	public static function build_card_data( $post ) {
		$category_terms = get_the_terms( $post->ID, Healthcare_Jobs_Categories::TAXONOMY );
		$location_terms = get_the_terms( $post->ID, self::LOCATION_TAXONOMY );

		$salary_min = get_post_meta( $post->ID, '_custom-number', true );
		$salary_range = get_post_meta( $post->ID, '_dirjob_salary', true );
		$salary_max = $salary_min;
		if ( $salary_range && false !== strpos( (string) $salary_range, '-' ) ) {
			$parts = explode( '-', (string) $salary_range );
			if ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) {
				$salary_max = (int) $parts[1];
			}
		}

		return array(
			'id'              => $post->ID,
			'external_job_id' => get_post_meta( $post->ID, Healthcare_Jobs_Directorist_Mapper::get_external_id_meta_key(), true ),
			'source'          => get_post_meta( $post->ID, 'healthcare_jobs_source', true ),
			'source_url'      => get_post_meta( $post->ID, '_djobs-apply-now', true ),
			'permalink'       => get_permalink( $post ),
			'title'           => get_the_title( $post ),
			'company_name'    => get_post_meta( $post->ID, '_custom-text', true ),
			'location'        => ! empty( $location_terms ) && ! is_wp_error( $location_terms ) ? $location_terms[0]->name : '',
			'employment_type' => get_post_meta( $post->ID, '_custom-select', true ),
			'remote_type'     => strtolower( (string) get_post_meta( $post->ID, '_dirjob_job_type', true ) ),
			'category'        => ! empty( $category_terms ) && ! is_wp_error( $category_terms ) ? $category_terms[0]->name : '',
			'salary_min'      => is_numeric( $salary_min ) ? (int) $salary_min : null,
			'salary_max'      => is_numeric( $salary_max ) ? (int) $salary_max : null,
			'salary_currency' => 'GBP',
			'posted_at'       => $post->post_date_gmt,
			'excerpt'         => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '' ),
			'status'          => $post->post_status,
		);
	}

	/**
	 * Resolves a category/specialty filter value (term ID, slug, or name)
	 * to a real term ID in Directorist's category taxonomy.
	 *
	 * @param mixed $value Term ID, slug, or display name.
	 * @return int Term ID, or 0 if it doesn't resolve to a real term.
	 */
	private static function resolve_category_term_id( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$value = sanitize_text_field( (string) $value );
		$term  = get_term_by( 'name', $value, Healthcare_Jobs_Categories::TAXONOMY );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $value ), Healthcare_Jobs_Categories::TAXONOMY );
		}
		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * Returns options for a filter dropdown, sourced from real Directorist
	 * data so the frontend never offers a choice that matches nothing.
	 *
	 * @param string $column One of: employment_type, remote_type.
	 * @return string[]
	 */
	public static function get_filter_options( $column ) {
		global $wpdb;

		$meta_key_map = array(
			'employment_type' => '_custom-select',
			'remote_type'     => '_dirjob_job_type',
		);

		if ( ! isset( $meta_key_map[ $column ] ) ) {
			return array();
		}

		$job_term_id = Healthcare_Jobs_Directorist_Mapper::get_job_listing_type_term_id();

		$sql = "SELECT DISTINCT pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE pm.meta_key = %s
			  AND pm.meta_value != ''
			  AND p.post_type = 'at_biz_dir'
			  AND p.post_status = 'publish'
			  AND tt.taxonomy = %s AND tt.term_id = %d
			ORDER BY pm.meta_value ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$values = $wpdb->get_col( $wpdb->prepare( $sql, $meta_key_map[ $column ], self::LISTING_TYPE_TAXONOMY, $job_term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $values ? $values : array();
	}

	/**
	 * Returns the top-level Directorist categories (the "Category" filter),
	 * for the search form dropdown.
	 *
	 * @return WP_Term[]
	 */
	public static function get_top_level_categories() {
		if ( ! taxonomy_exists( Healthcare_Jobs_Categories::TAXONOMY ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Healthcare_Jobs_Categories::TAXONOMY,
				'parent'     => 0,
				'hide_empty' => false,
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Returns every non-top-level Directorist category (used as the
	 * "Specialty" filter, since Directorist's taxonomy already expresses
	 * specialties as child terms of a category, e.g. Doctors -> Consultants).
	 *
	 * @return WP_Term[]
	 */
	public static function get_specialty_terms() {
		if ( ! taxonomy_exists( Healthcare_Jobs_Categories::TAXONOMY ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Healthcare_Jobs_Categories::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_values( array_filter( $terms, static function ( $term ) {
			return $term->parent > 0;
		} ) );
	}
}
