<?php
/**
 * Company (employer) records.
 *
 * A company can have many jobs. This table is the foundation for the future
 * healthcare business directory / employer profiles phase, so it is kept
 * independent of the jobs table and safe to query on its own.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Companies {

	/**
	 * Finds a company by its TheirStack external ID.
	 *
	 * @param string $external_id External company ID.
	 * @return array|null
	 */
	public static function find_by_external_id( $external_id ) {
		if ( empty( $external_id ) ) {
			return null;
		}
		global $wpdb;
		$table = Healthcare_Jobs_Database::companies_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE external_company_id = %s", $external_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Finds a company by exact name match, used when TheirStack does not
	 * provide a stable external company ID for a given source.
	 *
	 * @param string $name Company name.
	 * @return array|null
	 */
	public static function find_by_name( $name ) {
		if ( empty( $name ) ) {
			return null;
		}
		global $wpdb;
		$table = Healthcare_Jobs_Database::companies_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE company_name = %s ORDER BY id ASC LIMIT 1", $name ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Creates or updates a company from normalised job-import data and
	 * returns its local row ID.
	 *
	 * @param array $data {
	 *     @type string $external_company_id
	 *     @type string $company_name
	 *     @type string $website
	 *     @type string $industry
	 *     @type string $description
	 *     @type string $location
	 *     @type string $country
	 *     @type string $logo_url
	 * }
	 * @return int Local company ID.
	 */
	public static function upsert( array $data ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::companies_table();
		$now   = current_time( 'mysql', true );

		$company_name = isset( $data['company_name'] ) ? sanitize_text_field( $data['company_name'] ) : '';
		if ( '' === $company_name ) {
			$company_name = __( 'Unknown Employer', 'healthcare-jobs' );
		}

		$external_id = isset( $data['external_company_id'] ) ? sanitize_text_field( $data['external_company_id'] ) : '';

		$existing = null;
		if ( '' !== $external_id ) {
			$existing = self::find_by_external_id( $external_id );
		}
		if ( ! $existing ) {
			$existing = self::find_by_name( $company_name );
		}

		$fields = array(
			'external_company_id' => $external_id,
			'company_name'        => $company_name,
			'website'             => isset( $data['website'] ) ? esc_url_raw( $data['website'] ) : '',
			'industry'            => isset( $data['industry'] ) ? sanitize_text_field( $data['industry'] ) : null,
			'employer_type'       => self::classify_employer_type( $company_name, $data ),
			'description'         => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : null,
			'location'            => isset( $data['location'] ) ? sanitize_text_field( $data['location'] ) : null,
			'country'             => isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : null,
			'logo_url'            => isset( $data['logo_url'] ) ? esc_url_raw( $data['logo_url'] ) : null,
			'last_seen_at'        => $now,
			'updated_at'          => $now,
		);

		if ( $existing ) {
			// Never blank out a value we already had just because this
			// particular job listing omitted it.
			foreach ( array( 'website', 'industry', 'description', 'location', 'country', 'logo_url' ) as $field ) {
				if ( empty( $fields[ $field ] ) && ! empty( $existing[ $field ] ) ) {
					$fields[ $field ] = $existing[ $field ];
				}
			}
			if ( empty( $fields['employer_type'] ) && ! empty( $existing['employer_type'] ) ) {
				$fields['employer_type'] = $existing['employer_type'];
			}

			$wpdb->update( $table, $fields, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}

		$fields['first_seen_at'] = $now;
		$fields['created_at']    = $now;

		$wpdb->insert( $table, $fields );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Evidence-based employer classification. Only classifies as NHS when
	 * the company name or domain clearly indicates it; everything else
	 * stays unclassified rather than being assumed private. Site owners can
	 * refine this over time via the filter below without a plugin update.
	 *
	 * @param string $company_name Company name.
	 * @param array  $data         Raw company data from the import.
	 * @return string One of the employer_type taxonomy values, or ''.
	 */
	public static function classify_employer_type( $company_name, array $data = array() ) {
		$haystack = strtolower( $company_name . ' ' . ( $data['website'] ?? '' ) );
		$type     = '';

		if ( preg_match( '/\bnhs\b/i', $haystack ) ) {
			$type = 'nhs';
		} elseif ( preg_match( '/\bdental\b/i', $haystack ) ) {
			$type = 'dental';
		} elseif ( preg_match( '/\bpharmacy\b/i', $haystack ) ) {
			$type = 'pharmacy';
		} elseif ( preg_match( '/\bcare home\b/i', $haystack ) ) {
			$type = 'care_home';
		}

		/**
		 * Filters the employer-type classification for a company.
		 *
		 * Allows administrators to add rules (e.g. a curated list of known
		 * private hospital groups) once the future employer directory is
		 * built, without changing plugin code.
		 *
		 * @param string $type         Classification decided above ('' = unclassified).
		 * @param string $company_name Company name.
		 * @param array  $data         Raw company data.
		 */
		return apply_filters( 'healthcare_jobs_classify_employer_type', $type, $company_name, $data );
	}

	/**
	 * Recalculates and stores the active_jobs count for a company.
	 *
	 * @param int $company_id Company ID.
	 * @return void
	 */
	public static function recalculate_active_jobs( $company_id ) {
		global $wpdb;
		$jobs_table = Healthcare_Jobs_Database::jobs_table();
		$table      = Healthcare_Jobs_Database::companies_table();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$jobs_table} WHERE company_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$company_id
			)
		);

		$wpdb->update( $table, array( 'active_jobs' => $count ), array( 'id' => (int) $company_id ) );
	}

	/**
	 * Returns a single company by ID.
	 *
	 * @param int $id Company ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Healthcare_Jobs_Database::companies_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Returns total company count, for the dashboard.
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;
		$table = Healthcare_Jobs_Database::companies_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
