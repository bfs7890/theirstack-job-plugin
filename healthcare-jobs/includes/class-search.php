<?php
/**
 * Public-facing job search and filtering.
 *
 * Every query here reads only from the local wp_healthcare_jobs table
 * (never TheirStack), uses $wpdb->prepare() throughout, and relies on the
 * indexes/FULLTEXT key created in Healthcare_Jobs_Database so search stays
 * fast at thousands of rows.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Search {

	/**
	 * Runs a filtered, paginated search against active jobs only.
	 *
	 * @param array $args {
	 *     @type string $keyword          Free-text search (title/company/description).
	 *     @type string $location         Location text match.
	 *     @type string $category         Exact category match.
	 *     @type string $specialty        Exact specialty match.
	 *     @type string $employment_type  Exact employment type match.
	 *     @type string $remote_type      Exact remote type match.
	 *     @type int    $salary_min       Minimum salary the job must offer.
	 *     @type string $date_posted      One of: any, 24h, 7d, 14d, 30d.
	 *     @type int    $paged            1-indexed page number.
	 *     @type int    $per_page         Results per page.
	 * }
	 * @return array { items: array, total: int, pages: int }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$where  = array( "status = 'active'" );
		$params = array();

		$keyword = isset( $args['keyword'] ) ? trim( wp_strip_all_tags( $args['keyword'] ) ) : '';
		if ( strlen( $keyword ) >= 3 ) {
			// FULLTEXT boolean search scales far better than a leading-
			// wildcard LIKE once the table holds thousands of rows.
			$boolean_term = '+' . str_replace( array( '+', '-', '*', '"' ), '', $keyword ) . '*';
			$where[]      = 'MATCH(title, description, company_name) AGAINST (%s IN BOOLEAN MODE)';
			$params[]     = $boolean_term;
		} elseif ( '' !== $keyword ) {
			// Very short terms are not indexable by FULLTEXT (default
			// minimum word length); fall back to a bounded LIKE on the
			// indexed title column only.
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $keyword ) . '%';
		}

		if ( ! empty( $args['location'] ) ) {
			$where[]  = '(location LIKE %s OR city LIKE %s OR region LIKE %s OR postcode LIKE %s)';
			$like     = '%' . $wpdb->esc_like( trim( $args['location'] ) ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		foreach ( array( 'category', 'specialty', 'employment_type', 'remote_type' ) as $exact_field ) {
			if ( ! empty( $args[ $exact_field ] ) ) {
				$where[]  = "{$exact_field} = %s"; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$params[] = sanitize_text_field( $args[ $exact_field ] );
			}
		}

		if ( ! empty( $args['salary_min'] ) ) {
			$where[]  = '(salary_max IS NULL OR salary_max >= %d)';
			$params[] = absint( $args['salary_min'] );
		}

		if ( ! empty( $args['date_posted'] ) && 'any' !== $args['date_posted'] ) {
			$days_map = array(
				'24h' => 1,
				'7d'  => 7,
				'14d' => 14,
				'30d' => 30,
			);
			if ( isset( $days_map[ $args['date_posted'] ] ) ) {
				$where[]  = 'posted_at >= %s';
				$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days_map[ $args['date_posted'] ] * DAY_IN_SECONDS ) );
			}
		}

		$where_sql = implode( ' AND ', $where );

		$per_page = ! empty( $args['per_page'] ) ? min( 100, absint( $args['per_page'] ) ) : 20;
		$paged    = ! empty( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = empty( $params )
			? (int) $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$order_by = 'posted_at DESC, id DESC';
		$list_sql = "SELECT id, external_job_id, source, source_url, slug, title, company_id, company_name, location, city, region, country, employment_type, remote_type, salary_min, salary_max, salary_currency, category, specialty, posted_at, LEFT(description, 300) AS excerpt FROM {$table} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Returns distinct, non-empty values for a filter dropdown (category,
	 * specialty, employment_type, remote_type) from active jobs only.
	 *
	 * @param string $column One of the allowed column names.
	 * @return string[]
	 */
	public static function get_filter_options( $column ) {
		$allowed = array( 'category', 'specialty', 'employment_type', 'remote_type' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		global $wpdb;
		$table = Healthcare_Jobs_Database::jobs_table();

		$sql = "SELECT DISTINCT {$column} FROM {$table} WHERE status = 'active' AND {$column} IS NOT NULL AND {$column} != '' ORDER BY {$column} ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$values = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $values ? $values : array();
	}
}
