<?php
/**
 * Configurable healthcare categories and job-title classification rules.
 *
 * This is pure CRUD/config storage. Administrators manage these from
 * Healthcare Jobs > Categories without touching any PHP. Each category row
 * links to a real term in Directorist's own `at_biz_dir-category` taxonomy
 * via `directorist_term_id` - Directorist owns the category list itself,
 * this table only groups classification rules under it. The actual
 * title-matching logic lives in Healthcare_Jobs_Classifier.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Categories {

	const TAXONOMY = 'at_biz_dir-category';

	/**
	 * Default classification rules, seeded once on first install, keyed by
	 * the real Directorist category term slug on this site. Each title rule
	 * is either a plain string (unambiguous - matches on its own) or an
	 * array with:
	 *   - title:           the phrase to match (whole-word, case-insensitive)
	 *   - context_terms:   for ambiguous titles, at least one of these must
	 *                       also appear in the job title before it counts
	 *   - exclusion_terms:  if any of these appear anywhere in the job
	 *                       title, this rule never matches, even if the
	 *                       title/context matched (e.g. "Consultant" +
	 *                       "IT" must never classify as a clinical role).
	 *
	 * @return array<string, array>
	 */
	public static function get_default_data() {
		// Shared exclusion list for every clinical/ambiguous title rule:
		// generic business/administrative/technology job titles that
		// happen to contain a word like "Consultant" or "GP" without being
		// healthcare roles at all.
		$generic_exclusions = array(
			'IT', 'Health & Safety', 'Health and Safety', 'Tile', 'Sales',
			'Recruitment', 'Management Consultant', 'HR', 'Human Resources',
			'Finance', 'Financial', 'Marketing', 'Software', 'Security',
			'Environmental', 'Business', 'Technology', 'Digital', 'Fire',
			'SEO', 'Legal', 'Tax', 'Immigration', 'Wedding', 'Interior',
			'Insurance', 'Property', 'Education', 'Reception', 'Receptionist',
			'Administrator', 'Admin',
		);

		// Both the personal-role form ("Cardiologist") and the specialty/
		// subject-noun form ("Cardiology") are included, because real UK job
		// titles use either pattern interchangeably ("Consultant
		// Cardiologist" vs "Consultant in Cardiology" / "Consultant
		// Cardiology") - matching only the person-role form missed titles
		// like "Consultant in Obstetrics and Gynaecology" entirely.
		$clinical_modifiers = array(
			'Physician', 'Surgeon', 'Surgery', 'Medicine',
			'Cardiologist', 'Cardiology',
			'Psychiatrist', 'Psychiatry',
			'Paediatrician', 'Pediatrician', 'Paediatrics', 'Pediatrics',
			'Radiologist', 'Radiology',
			'Anaesthetist', 'Anesthetist', 'Anaesthesia', 'Anesthesia', 'Anaesthetics', 'Anesthetics',
			'Oncologist', 'Oncology', 'Oncological',
			'Dermatologist', 'Dermatology',
			'Obstetrician', 'Obstetrics',
			'Gynaecologist', 'Gynecologist', 'Gynaecology', 'Gynecology',
			'Ophthalmologist', 'Ophthalmology',
			'Orthopaedic', 'Orthopedic', 'Orthopaedics', 'Orthopedics',
			'Neurologist', 'Neurology',
			'Endocrinologist', 'Endocrinology',
			'Urologist', 'Urology',
			'Rheumatologist', 'Rheumatology',
			'Haematologist', 'Hematologist', 'Haematology', 'Hematology',
			'Nephrologist', 'Nephrology',
			'Gastroenterologist', 'Gastroenterology',
			'Immunologist', 'Immunology',
			'Pathologist', 'Pathology',
			'Geriatrician', 'Geriatrics', 'Geriatric Medicine', 'Elderly Care',
			'Respiratory', 'Vascular', 'Colorectal', 'Trauma', 'Renal',
			'Palliative', 'Palliative Care', 'Intensive Care', 'Critical Care',
			'Stroke', 'Maxillofacial', 'ENT', 'Ear Nose and Throat',
			'A&E', 'Emergency Medicine', 'ICU', 'Acute Medicine',
		);

		return array(
			'doctors'                      => array(
				'Doctor',
				'Medical Officer',
			),
			'general-practitioners-gp'     => array(
				// "GP" (unlike "Consultant") is not a term used across other
				// industries, so it does not need a required context term -
				// exclusion_terms alone is enough to keep it from matching
				// compound titles like "GP Reception Administrator" or "GP
				// Practice Manager" that belong to their own categories
				// (those also win on longest-match anyway).
				array(
					'title'           => 'GP',
					'is_ambiguous'    => false,
					'exclusion_terms' => $generic_exclusions,
				),
				'General Practitioner',
			),
			'consultants'                   => array(
				array(
					'title'           => 'Consultant',
					'is_ambiguous'    => true,
					'context_terms'   => $clinical_modifiers,
					'exclusion_terms' => $generic_exclusions,
				),
			),
			'specialty-doctors'             => array( 'Specialty Doctor' ),
			'resident-medical-officers'     => array( 'Resident Medical Officer', 'RMO' ),
			'nurses'                        => array( 'Registered Nurse', 'Staff Nurse', 'Nurse' ),
			'advanced-nurse-practitioners'  => array( 'Advanced Nurse Practitioner', 'ANP' ),
			'healthcare-assistants'         => array( 'Healthcare Assistant', 'HCA' ),
			'midwives'                      => array( 'Midwife' ),
			'pharmacists'                   => array( 'Pharmacist' ),
			'dentists'                      => array( 'Dentist' ),
			'optometrists'                  => array( 'Optometrist' ),
			'paramedics'                    => array( 'Paramedic' ),
			'psychologists'                 => array( 'Psychologist' ),
			'psychiatrists'                 => array( 'Psychiatrist' ),
			'dietitians'                    => array( 'Dietitian' ),
			'occupational-therapists'       => array( 'Occupational Therapist' ),
			'physiotherapists'              => array( 'Physiotherapist' ),
			'podiatrists'                   => array( 'Podiatrist' ),
			'radiographers'                 => array( 'Radiographer' ),
			'speech-and-language-therapist' => array( 'Speech and Language Therapist', 'SLT' ),
			'counsellors'                   => array( 'Counsellor', 'Counselor' ),
			'mental-health-nurses'          => array( 'Mental Health Nurse' ),
			'psychotherapists'              => array( 'Psychotherapist' ),
			'care-assistants'               => array( 'Care Assistant' ),
			'care-home-managers'            => array( 'Care Home Manager' ),
			'domiciliary-care-workers'      => array( 'Domiciliary Care Worker' ),
			'registered-managers'           => array( 'Registered Manager' ),
			'senior-carers'                 => array( 'Senior Carer' ),
			'supported-living-managers'     => array( 'Supported Living Manager' ),
			'dental-hygienists'             => array( 'Dental Hygienist' ),
			'dental-nurses'                 => array( 'Dental Nurse' ),
			'orthodontists'                 => array( 'Orthodontist' ),
			'pharmacy-assistants'           => array( 'Pharmacy Assistant' ),
			'pharmacy-technicians'          => array( 'Pharmacy Technician' ),
			'compliance-officers'           => array( 'Compliance Officer' ),
			'medical-secretaries'           => array( 'Medical Secretary' ),
			'practice-managers'             => array( 'Practice Manager', 'GP Practice Manager' ),
			'receptionists'                 => array( 'Receptionist', 'GP Receptionist', 'Medical Receptionist', 'GP Reception Administrator', 'Reception Administrator' ),
			'biomedical-scientists'         => array( 'Biomedical Scientist' ),
			'phlebotomists'                 => array( 'Phlebotomist' ),
			'radiologists'                  => array( 'Radiologist' ),
			'sonographers'                  => array( 'Sonographer' ),
			'expert-witnesses'              => array(
				array(
					'title'         => 'Expert Witness',
					'is_ambiguous'  => true,
					'context_terms' => array( 'Medical', 'Clinical', 'Doctor', 'Consultant', 'Medicolegal', 'Personal Injury', 'Clinical Negligence' ),
				),
			),
			'independent-medical-examiners' => array( 'Independent Medical Examiner', 'IME' ),
		);
	}

	/**
	 * Inserts the default classification rules, resolving each slug against
	 * Directorist's real category taxonomy. A slug that doesn't exist on
	 * this install is skipped (never invented) - only ever called once,
	 * from Healthcare_Jobs_Database::install() guarded by an option flag.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		global $wpdb;

		$cat_table   = Healthcare_Jobs_Database::categories_table();
		$title_table = Healthcare_Jobs_Database::job_titles_table();
		$now         = current_time( 'mysql', true );
		$order       = 0;

		foreach ( self::get_default_data() as $term_slug => $title_rules ) {
			$term = taxonomy_exists( self::TAXONOMY ) ? get_term_by( 'slug', $term_slug, self::TAXONOMY ) : false;

			if ( ! $term || is_wp_error( $term ) ) {
				// This site doesn't have that Directorist category (yet) -
				// skip rather than guess a term ID that doesn't exist.
				continue;
			}

			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$cat_table} WHERE directorist_term_id = %d", $term->term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $existing ) {
				$category_id = (int) $existing;
			} else {
				$wpdb->insert(
					$cat_table,
					array(
						'name'                => $term->name,
						'slug'                => $term_slug,
						'directorist_term_id' => $term->term_id,
						'menu_order'          => $order,
						'created_at'          => $now,
						'updated_at'          => $now,
					),
					array( '%s', '%s', '%d', '%d', '%s', '%s' )
				);
				$category_id = (int) $wpdb->insert_id;
			}

			foreach ( $title_rules as $rule ) {
				$rule = is_array( $rule ) ? $rule : array( 'title' => $rule );

				$title_exists = $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$title_table} WHERE category_id = %d AND title = %s", $category_id, $rule['title'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
				if ( $title_exists ) {
					continue;
				}

				$wpdb->insert(
					$title_table,
					array(
						'category_id'     => $category_id,
						'title'           => $rule['title'],
						'is_ambiguous'    => ! empty( $rule['is_ambiguous'] ) ? 1 : 0,
						'context_terms'   => ! empty( $rule['context_terms'] ) ? implode( '|', $rule['context_terms'] ) : null,
						'exclusion_terms' => ! empty( $rule['exclusion_terms'] ) ? implode( '|', $rule['exclusion_terms'] ) : null,
						'created_at'      => $now,
					),
					array( '%d', '%s', '%d', '%s', '%s', '%s' )
				);
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
	 * Returns every real term in Directorist's category taxonomy, for the
	 * admin UI's "link this rule group to a category" dropdown.
	 *
	 * @return WP_Term[]
	 */
	public static function get_directorist_terms() {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
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
	 * Returns every classification rule joined with its category's
	 * Directorist term ID, for Healthcare_Jobs_Classifier to evaluate.
	 *
	 * @return array Each row: title, is_ambiguous, context_terms (array),
	 *               exclusion_terms (array), directorist_term_id, category_name.
	 */
	public static function get_classification_rules() {
		global $wpdb;
		$cat_table   = Healthcare_Jobs_Database::categories_table();
		$title_table = Healthcare_Jobs_Database::job_titles_table();

		$rows = $wpdb->get_results(
			"SELECT t.title, t.is_ambiguous, t.context_terms, t.exclusion_terms,
			        c.directorist_term_id, c.name AS category_name
			 FROM {$title_table} t
			 INNER JOIN {$cat_table} c ON c.id = t.category_id
			 WHERE c.directorist_term_id IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$rules = array();
		foreach ( (array) $rows as $row ) {
			$rules[] = array(
				'title'               => $row['title'],
				'is_ambiguous'        => ! empty( $row['is_ambiguous'] ),
				'context_terms'       => ! empty( $row['context_terms'] ) ? explode( '|', $row['context_terms'] ) : array(),
				'exclusion_terms'     => ! empty( $row['exclusion_terms'] ) ? explode( '|', $row['exclusion_terms'] ) : array(),
				'directorist_term_id' => (int) $row['directorist_term_id'],
				'category_name'       => $row['category_name'],
			);
		}
		return $rules;
	}

	/**
	 * Adds a new category, linked to a real Directorist taxonomy term.
	 *
	 * @param string $name                Category name (for our own display).
	 * @param int    $directorist_term_id Real term ID in at_biz_dir-category.
	 * @return int|WP_Error New category ID.
	 */
	public static function add_category( $name, $directorist_term_id = 0 ) {
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
				'name'                => $name,
				'slug'                => $slug,
				'directorist_term_id' => $directorist_term_id ? (int) $directorist_term_id : null,
				'menu_order'          => $max_order + 1,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
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
	 * Adds a job title classification rule under a category.
	 *
	 * @param int    $category_id     Category ID.
	 * @param string $title           Job title text.
	 * @param bool   $is_ambiguous    Whether this title needs a context term.
	 * @param array  $context_terms   Required co-occurring terms if ambiguous.
	 * @param array  $exclusion_terms Terms that veto this match if present.
	 * @return int|WP_Error
	 */
	public static function add_title( $category_id, $title, $is_ambiguous = false, array $context_terms = array(), array $exclusion_terms = array() ) {
		global $wpdb;

		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return new WP_Error( 'healthcare_jobs_invalid_title', __( 'Job title cannot be empty.', 'healthcare-jobs' ) );
		}

		$table = Healthcare_Jobs_Database::job_titles_table();

		$wpdb->insert(
			$table,
			array(
				'category_id'     => (int) $category_id,
				'title'           => $title,
				'is_ambiguous'    => $is_ambiguous ? 1 : 0,
				'context_terms'   => ! empty( $context_terms ) ? implode( '|', array_map( 'sanitize_text_field', $context_terms ) ) : null,
				'exclusion_terms' => ! empty( $exclusion_terms ) ? implode( '|', array_map( 'sanitize_text_field', $exclusion_terms ) ) : null,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
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
