<?php
/**
 * Maps a normalised TheirStack job onto Directorist's real field schema.
 *
 * The field keys used here (_custom-select, _dirjob_salary, _djobs-apply-now,
 * etc.) were read directly from this site's live Directorist configuration
 * (Directory Builder's `submission_form_fields` term meta on the "Jobs"
 * listing type, term_id 269, plus the dJobs/Job Manager add-on's own
 * meta keys observed on an existing listing) - not guessed. See the
 * plugin README for how they were confirmed.
 *
 * This class only builds a plain PHP array describing what should be
 * written; Healthcare_Jobs_Directorist_Sync is what actually performs the
 * wp_insert_post()/update_post_meta()/wp_set_object_terms() calls.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Directorist_Mapper {

	/**
	 * Term ID of the "Jobs" listing type in the atbdp_listing_types taxonomy.
	 * Confirmed on this install; overridable via filter for other sites.
	 *
	 * @return int
	 */
	public static function get_job_listing_type_term_id() {
		/**
		 * Filters the Directorist listing-type term ID that imported jobs
		 * are created under.
		 *
		 * @param int $term_id Default listing type term ID.
		 */
		return (int) apply_filters( 'healthcare_jobs_directorist_listing_type_id', 269 );
	}

	/**
	 * Meta key used to store the field-key-style postmeta for our own
	 * external-ID reference is intentionally NOT prefixed with an
	 * underscore, so it never collides with a Directorist-managed private
	 * field and remains easy to spot when inspecting postmeta directly.
	 *
	 * @return string
	 */
	public static function get_external_id_meta_key() {
		return 'healthcare_jobs_theirstack_id';
	}

	/**
	 * UK city/region keywords mapped to this site's actual
	 * at_biz_dir-location term IDs. Only the regions that exist in this
	 * site's taxonomy are covered (London, Midlands, North West, Scotland,
	 * South East, Wales) - anything else UK-based falls back to the
	 * country-level "United Kingdom" term rather than being force-fit into
	 * the wrong region. Extend via the filter below if the taxonomy grows.
	 *
	 * @return array<string,int>
	 */
	public static function get_location_keyword_map() {
		$map = array(
			'london'      => 204,
			'birmingham'  => 206,
			'nottingham'  => 206,
			'leicester'   => 206,
			'coventry'    => 206,
			'derby'       => 206,
			'wolverhampton' => 206,
			'stoke'       => 206,
			'midlands'    => 206,
			'manchester'  => 207,
			'liverpool'   => 207,
			'preston'     => 207,
			'blackpool'   => 207,
			'bolton'      => 207,
			'warrington'  => 207,
			'chester'     => 207,
			'lancashire'  => 207,
			'cumbria'     => 207,
			'cheshire'    => 207,
			'edinburgh'   => 208,
			'glasgow'     => 208,
			'aberdeen'    => 208,
			'dundee'      => 208,
			'scotland'    => 208,
			'brighton'    => 205,
			'oxford'      => 205,
			'reading'     => 205,
			'southampton' => 205,
			'portsmouth'  => 205,
			'kent'        => 205,
			'surrey'      => 205,
			'sussex'      => 205,
			'hampshire'   => 205,
			'cardiff'     => 209,
			'swansea'     => 209,
			'newport'     => 209,
			'wales'       => 209,
		);

		/**
		 * Filters the location keyword -> Directorist term ID map.
		 *
		 * @param array $map Default keyword map.
		 */
		return apply_filters( 'healthcare_jobs_directorist_location_map', $map );
	}

	/**
	 * Term ID for the generic "United Kingdom" location, used as a fallback
	 * when a job's city/region doesn't match a known sub-region.
	 *
	 * @return int
	 */
	public static function get_uk_fallback_location_term_id() {
		return (int) apply_filters( 'healthcare_jobs_directorist_uk_location_id', 203 );
	}

	/**
	 * Country-code-based fallback for the handful of non-UK location terms
	 * this site has configured.
	 *
	 * @return array<string,int>
	 */
	public static function get_country_code_location_map() {
		return apply_filters(
			'healthcare_jobs_directorist_country_location_map',
			array(
				'SA' => 211,
				'TR' => 212,
				'AE' => 210,
			)
		);
	}

	/**
	 * Builds the full Directorist-ready payload for one normalised job.
	 *
	 * @param array $job    Normalised job data (see Healthcare_Jobs_Importer).
	 * @param int   $category_term_id Directorist category term ID (0 = unclassified).
	 * @return array{post: array, meta: array, category_term_id: int, location_term_id: int}
	 */
	public static function map( array $job, $category_term_id ) {
		$post = array(
			'post_type'   => 'at_biz_dir',
			'post_title'  => wp_strip_all_tags( (string) ( $job['title'] ?? '' ) ),
			'post_content' => wp_kses_post( (string) ( $job['description'] ?? '' ) ),
			'post_status' => ! empty( $job['is_closed'] ) ? self::get_closed_post_status() : 'publish',
		);

		$meta = array(
			'_directory_type'   => self::get_job_listing_type_term_id(),
			'_custom-select'    => self::map_employment_type( $job['employment_type'] ?? '' ),
			'_dirjob_job_type'  => self::map_remote_type( $job['remote_type'] ?? '' ),
			'_custom-text'      => sanitize_text_field( (string) ( $job['company_name'] ?? '' ) ),
			'_custom-url'       => ! empty( $job['company_website'] ) ? esc_url_raw( $job['company_website'] ) : '',
			'_zip'              => sanitize_text_field( (string) ( $job['postcode'] ?? '' ) ),
			'_djobs-apply-now'  => ! empty( $job['source_url'] ) ? esc_url_raw( $job['source_url'] ) : '',
			// Deliberately never populated for TheirStack-aggregated jobs:
			// this is the on-site application form. Populating it would
			// silently invite candidates to "apply" through this site for
			// a job we do not own, which the plugin must never do.
			'_dirjob_apply_form' => '',
			self::get_external_id_meta_key() => sanitize_text_field( (string) ( $job['external_job_id'] ?? '' ) ),
			'healthcare_jobs_source' => sanitize_text_field( (string) ( $job['source'] ?? 'theirstack' ) ),
			'healthcare_jobs_raw_data' => wp_json_encode( $job['raw_data'] ?? array() ),
		);

		list( $salary_range, $salary_number ) = self::map_salary( $job );
		if ( '' !== $salary_range ) {
			$meta['_dirjob_salary'] = $salary_range;
		}
		if ( null !== $salary_number ) {
			$meta['_custom-number'] = $salary_number;
		}

		if ( ! empty( $job['closing_date'] ) ) {
			$date = self::format_date( $job['closing_date'] );
			if ( $date ) {
				$meta['_dirjob_deadline'] = $date;
				$meta['_custom-date']    = $date;
			}
		}

		// Expiration: use Directorist's own expiry mechanism rather than a
		// bespoke status field, so its existing cron/renewal/deletion rules
		// apply uniformly to aggregated and direct listings alike.
		if ( ! empty( $job['is_closed'] ) ) {
			$meta['_never_expire'] = 0;
			$meta['_expiry_date']  = current_time( 'mysql' );
		} else {
			$meta['_never_expire'] = 0;
			$meta['_expiry_date']  = self::compute_expiry_date( $job );
		}

		return array(
			'post'              => $post,
			'meta'              => $meta,
			'category_term_id'  => (int) $category_term_id,
			'location_term_id'  => self::resolve_location_term_id( $job ),
		);
	}

	/**
	 * The post_status used for a job TheirStack reports as closed.
	 * Directorist registers a custom 'expired' post status for this
	 * purpose; falls back to 'draft' (never publicly visible, never
	 * silently deleted) if that status isn't registered on this install.
	 *
	 * @return string
	 */
	public static function get_closed_post_status() {
		global $wp_post_statuses;
		if ( isset( $wp_post_statuses['expired'] ) ) {
			return 'expired';
		}
		return 'draft';
	}

	/**
	 * Directorist's default listing lifetime (days) for the currently
	 * configured Jobs listing type (term meta `default_expiration`),
	 * falling back to 30 days if unset.
	 *
	 * @param array $job Normalised job data.
	 * @return string MySQL datetime.
	 */
	private static function compute_expiry_date( array $job ) {
		$days = (int) get_term_meta( self::get_job_listing_type_term_id(), 'default_expiration', true );
		if ( $days <= 0 ) {
			$days = 30;
		}
		$base = ! empty( $job['posted_at'] ) ? strtotime( $job['posted_at'] ) : time();
		if ( false === $base ) {
			$base = time();
		}
		return gmdate( 'Y-m-d H:i:s', $base + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Maps a free-text employment type onto Directorist's fixed dropdown
	 * options (Full-time/Part-time/Contract/Internship). Unrecognised
	 * values pass through unchanged rather than being forced into a
	 * potentially wrong bucket.
	 *
	 * @param string $value Raw employment type.
	 * @return string
	 */
	private static function map_employment_type( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		$map = array(
			'full-time' => 'Full-time',
			'full_time' => 'Full-time',
			'fulltime'  => 'Full-time',
			'permanent' => 'Full-time',
			'part-time' => 'Part-time',
			'part_time' => 'Part-time',
			'parttime'  => 'Part-time',
			'contract'  => 'Contract',
			'fixed-term' => 'Contract',
			'fixed_term' => 'Contract',
			'temporary' => 'Contract',
			'locum'     => 'Contract',
			'internship' => 'Internship',
			'intern'    => 'Internship',
		);
		return $map[ $value ] ?? sanitize_text_field( $value );
	}

	/**
	 * Maps a remote_type value onto the dJobs "Job Type" (work location)
	 * vocabulary observed in live data ("Remote", presumably "Onsite"/
	 * "Hybrid" analogously).
	 *
	 * @param string $value Raw remote type (remote|onsite|hybrid|'').
	 * @return string
	 */
	private static function map_remote_type( $value ) {
		$map = array(
			'remote' => 'Remote',
			'onsite' => 'Onsite',
			'hybrid' => 'Hybrid',
		);
		return $map[ strtolower( trim( (string) $value ) ) ] ?? '';
	}

	/**
	 * Builds both the dJobs salary range string and a single representative
	 * number for the generic Field Builder salary field.
	 *
	 * @param array $job Normalised job data.
	 * @return array{0:string,1:int|null}
	 */
	private static function map_salary( array $job ) {
		$min = isset( $job['salary_min'] ) && is_numeric( $job['salary_min'] ) ? (int) $job['salary_min'] : null;
		$max = isset( $job['salary_max'] ) && is_numeric( $job['salary_max'] ) ? (int) $job['salary_max'] : null;

		if ( null === $min && null === $max ) {
			return array( '', null );
		}

		if ( null !== $min && null !== $max ) {
			return array( $min . '-' . $max, $min );
		}

		$only = null !== $min ? $min : $max;
		return array( (string) $only, $only );
	}

	/**
	 * Resolves a job's location string to a Directorist location term ID,
	 * preferring the most specific match this site's taxonomy supports.
	 *
	 * @param array $job Normalised job data.
	 * @return int Term ID, or 0 if nothing could be resolved.
	 */
	private static function resolve_location_term_id( array $job ) {
		$haystack = strtolower( trim( ( $job['city'] ?? '' ) . ' ' . ( $job['region'] ?? '' ) . ' ' . ( $job['location'] ?? '' ) ) );

		if ( '' !== $haystack ) {
			foreach ( self::get_location_keyword_map() as $keyword => $term_id ) {
				if ( false !== strpos( $haystack, $keyword ) ) {
					return (int) $term_id;
				}
			}
		}

		$country_code = strtoupper( (string) ( $job['country_code'] ?? '' ) );

		if ( 'GB' === $country_code || 'UK' === $country_code ) {
			return self::get_uk_fallback_location_term_id();
		}

		$country_map = self::get_country_code_location_map();
		if ( isset( $country_map[ $country_code ] ) ) {
			return (int) $country_map[ $country_code ];
		}

		return 0;
	}

	/**
	 * Parses a date string into MySQL DATETIME, or null if unparseable.
	 *
	 * @param string $value Raw date string.
	 * @return string|null
	 */
	private static function format_date( $value ) {
		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
