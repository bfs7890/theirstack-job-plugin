<?php
/**
 * Configurable healthcare categories and job titles.
 *
 * Administrators manage these from Healthcare Jobs > Categories without
 * touching any PHP. They drive both the TheirStack search filters and the
 * classification of imported jobs into a category.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Categories {

	/**
	 * Default categories and their starter job titles, used only to seed
	 * the database on first install.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_default_data() {
		return array(
			'Doctors'               => array(
				'Doctor',
				'GP',
				'General Practitioner',
				'Consultant',
				'Specialty Doctor',
				'Medical Officer',
				'Clinical Lead',
				'Medical Director',
			),
			'Nursing'               => array(
				'Registered Nurse',
				'Staff Nurse',
				'Practice Nurse',
				'Clinical Nurse',
				'Nurse Manager',
			),
			'Allied Health'         => array(
				'Physiotherapist',
				'Occupational Therapist',
				'Podiatrist',
				'Radiographer',
				'Dietitian',
				'Speech and Language Therapist',
			),
			'Pharmacy'              => array(
				'Pharmacist',
				'Pharmacy Manager',
				'Pharmacy Technician',
			),
			'Dental'                => array(
				'Dentist',
				'Dental Nurse',
				'Dental Hygienist',
				'Dental Therapist',
			),
			'Healthcare Management' => array(
				'Practice Manager',
				'Clinical Manager',
				'Healthcare Manager',
				'Operations Manager',
			),
		);
	}

	/**
	 * Inserts the default categories/titles. Only ever called once, from
	 * Healthcare_Jobs_Database::install() guarded by an option flag.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		global $wpdb;

		$cat_table   = Healthcare_Jobs_Database::categories_table();
		$title_table = Healthcare_Jobs_Database::job_titles_table();
		$now         = current_time( 'mysql', true );
		$order       = 0;

		foreach ( self::get_default_data() as $category_name => $titles ) {
			$slug = sanitize_title( $category_name );

			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$cat_table} WHERE slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $existing ) {
				$category_id = (int) $existing;
			} else {
				$wpdb->insert(
					$cat_table,
					array(
						'name'       => $category_name,
						'slug'       => $slug,
						'menu_order' => $order,
						'created_at' => $now,
						'updated_at' => $now,
					),
					array( '%s', '%s', '%d', '%s', '%s' )
				);
				$category_id = (int) $wpdb->insert_id;
			}

			foreach ( $titles as $title ) {
				$title_exists = $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$title_table} WHERE category_id = %d AND title = %s", $category_id, $title ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
				if ( ! $title_exists ) {
					$wpdb->insert(
						$title_table,
						array(
							'category_id' => $category_id,
							'title'       => $title,
							'created_at'  => $now,
						),
						array( '%d', '%s', '%s' )
					);
				}
			}

			++$order;
		}
	}

	/**
	 * Returns all categories ordered for display.
	 *
	 * @return array
	 */
	public static function get_all() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::categories_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY menu_order ASC, name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $rows ? $rows : array();
	}

	/**
	 * Returns category names only, for dropdown filters.
	 *
	 * @return string[]
	 */
	public static function get_names() {
		return wp_list_pluck( self::get_all(), 'name' );
	}

	/**
	 * Returns all job titles for one category.
	 *
	 * @param int $category_id Category ID.
	 * @return array
	 */
	public static function get_titles_for_category( $category_id ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::job_titles_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE category_id = %d ORDER BY title ASC", (int) $category_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * Returns every configured job title across all categories, flattened,
	 * for use as the TheirStack job_title_or search filter.
	 *
	 * @return string[]
	 */
	public static function get_all_titles() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::job_titles_table();
		$rows  = $wpdb->get_col( "SELECT DISTINCT title FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $rows ? $rows : array();
	}

	/**
	 * Builds a lookup map of lowercased job title => category name, used to
	 * classify an imported job by matching its title.
	 *
	 * @return array<string,string>
	 */
	public static function get_title_category_map() {
		global $wpdb;
		$cat_table   = Healthcare_Jobs_Database::categories_table();
		$title_table = Healthcare_Jobs_Database::job_titles_table();

		$rows = $wpdb->get_results(
			"SELECT t.title AS title, c.name AS category FROM {$title_table} t INNER JOIN {$cat_table} c ON c.id = t.category_id", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ strtolower( trim( $row['title'] ) ) ] = $row['category'];
		}
		return $map;
	}

	/**
	 * Guesses the healthcare category for a given job title by matching
	 * configured titles as whole-word substrings (case-insensitive).
	 *
	 * @param string $job_title Job title from an imported job.
	 * @return string Category name, or '' if no match.
	 */
	public static function classify_title( $job_title ) {
		$job_title = strtolower( (string) $job_title );
		if ( '' === $job_title ) {
			return '';
		}

		$map = self::get_title_category_map();

		// Exact match first.
		if ( isset( $map[ $job_title ] ) ) {
			return $map[ $job_title ];
		}

		// Fall back to substring matching, longest title first so
		// "Specialty Doctor" wins over "Doctor" when both appear.
		$titles = array_keys( $map );
		usort(
			$titles,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $titles as $title ) {
			if ( false !== strpos( $job_title, $title ) ) {
				return $map[ $title ];
			}
		}

		return '';
	}

	/**
	 * Adds a new category.
	 *
	 * @param string $name Category name.
	 * @return int|WP_Error New category ID.
	 */
	public static function add_category( $name ) {
		global $wpdb;

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'healthcare_jobs_invalid_category', __( 'Category name cannot be empty.', 'healthcare-jobs' ) );
		}

		$table = Healthcare_Jobs_Database::categories_table();
		$slug  = sanitize_title( $name );
		$now   = current_time( 'mysql', true );

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return new WP_Error( 'healthcare_jobs_duplicate_category', __( 'A category with this name already exists.', 'healthcare-jobs' ) );
		}

		$max_order = (int) $wpdb->get_var( "SELECT COALESCE(MAX(menu_order), -1) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$wpdb->insert(
			$table,
			array(
				'name'       => $name,
				'slug'       => $slug,
				'menu_order' => $max_order + 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes a category and its job titles.
	 *
	 * @param int $category_id Category ID.
	 * @return void
	 */
	public static function delete_category( $category_id ) {
		global $wpdb;
		$cat_table   = Healthcare_Jobs_Database::categories_table();
		$title_table = Healthcare_Jobs_Database::job_titles_table();

		$wpdb->delete( $title_table, array( 'category_id' => (int) $category_id ), array( '%d' ) );
		$wpdb->delete( $cat_table, array( 'id' => (int) $category_id ), array( '%d' ) );
	}

	/**
	 * Adds a job title under a category.
	 *
	 * @param int    $category_id Category ID.
	 * @param string $title       Job title text.
	 * @return int|WP_Error
	 */
	public static function add_title( $category_id, $title ) {
		global $wpdb;

		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return new WP_Error( 'healthcare_jobs_invalid_title', __( 'Job title cannot be empty.', 'healthcare-jobs' ) );
		}

		$table = Healthcare_Jobs_Database::job_titles_table();

		$wpdb->insert(
			$table,
			array(
				'category_id' => (int) $category_id,
				'title'       => $title,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes a job title.
	 *
	 * @param int $title_id Title row ID.
	 * @return void
	 */
	public static function delete_title( $title_id ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::job_titles_table();
		$wpdb->delete( $table, array( 'id' => (int) $title_id ), array( '%d' ) );
	}
}
