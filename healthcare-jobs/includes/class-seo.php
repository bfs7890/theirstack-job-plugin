<?php
/**
 * SEO-friendly job URLs, canonical tags, and JobPosting structured data.
 *
 * Individual jobs live in a custom table, not wp_posts, so pretty URLs are
 * produced with a lightweight rewrite rule + query var + template_include,
 * rather than a custom post type.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_SEO {

	const QUERY_VAR = 'healthcare_job_slug';

	/**
	 * Base URL segment for single job pages, e.g. /healthcare-jobs/{slug}/.
	 *
	 * @return string
	 */
	public static function get_base_slug() {
		/**
		 * Filters the URL base used for individual job pages.
		 *
		 * @param string $base Default 'healthcare-jobs'.
		 */
		return apply_filters( 'healthcare_jobs_url_base', 'healthcare-jobs' );
	}

	/**
	 * Registers the rewrite rule and query var. Hooked to `init`.
	 *
	 * @return void
	 */
	public static function register_rewrites() {
		$base = self::get_base_slug();

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		if ( get_option( 'healthcare_jobs_flush_rewrites' ) ) {
			flush_rewrite_rules();
			delete_option( 'healthcare_jobs_flush_rewrites' );
		}
	}

	/**
	 * Registers our query var so WP_Query/WP recognises it.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Swaps in our single-job template whenever the query var is present.
	 *
	 * @param string $template Template WordPress intends to load.
	 * @return string
	 */
	public static function maybe_load_job_template( $template ) {
		$slug = get_query_var( self::QUERY_VAR );

		if ( empty( $slug ) ) {
			return $template;
		}

		$custom = HEALTHCARE_JOBS_PLUGIN_DIR . 'templates/single-healthcare-job.php';

		if ( file_exists( $custom ) ) {
			status_header( 200 );
			return $custom;
		}

		return $template;
	}

	/**
	 * Builds the canonical public URL for a job.
	 *
	 * @param array $job Job row (needs 'slug').
	 * @return string
	 */
	public static function get_job_url( array $job ) {
		return home_url( '/' . self::get_base_slug() . '/' . rawurlencode( $job['slug'] ) . '/' );
	}

	/**
	 * Outputs a canonical link tag on single job pages.
	 *
	 * @return void
	 */
	public static function output_canonical() {
		$slug = get_query_var( self::QUERY_VAR );
		if ( empty( $slug ) ) {
			return;
		}
		$job = Healthcare_Jobs_Jobs::get_by_slug( $slug );
		if ( ! $job ) {
			return;
		}
		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( self::get_job_url( $job ) ) );
	}

	/**
	 * Keeps closed/expired job pages out of search indexes without
	 * deleting the historical record.
	 *
	 * @param array $robots Existing robots directives.
	 * @return array
	 */
	public static function filter_robots( $robots ) {
		$slug = get_query_var( self::QUERY_VAR );
		if ( empty( $slug ) ) {
			return $robots;
		}
		$job = Healthcare_Jobs_Jobs::get_by_slug( $slug );
		if ( $job && 'active' !== $job['status'] ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = false;
		}
		return $robots;
	}

	/**
	 * Prints schema.org JobPosting JSON-LD on a single job page. Only
	 * fields we actually have data for are included — nothing is
	 * fabricated (salary, closing date, etc. are omitted when unknown).
	 *
	 * @return void
	 */
	public static function output_structured_data() {
		$slug = get_query_var( self::QUERY_VAR );
		if ( empty( $slug ) ) {
			return;
		}

		$job = Healthcare_Jobs_Jobs::get_by_slug( $slug );
		if ( ! $job || empty( $job['title'] ) || empty( $job['description'] ) ) {
			return;
		}

		$data = array(
			'@context'    => 'https://schema.org/',
			'@type'       => 'JobPosting',
			'title'       => wp_strip_all_tags( $job['title'] ),
			'description' => wp_kses_post( $job['description'] ),
			'identifier'  => array(
				'@type' => 'PropertyValue',
				'name'  => 'TheirStack',
				'value' => $job['external_job_id'],
			),
			'directApply' => false,
		);

		if ( ! empty( $job['posted_at'] ) ) {
			$data['datePosted'] = gmdate( 'Y-m-d', strtotime( $job['posted_at'] ) );
		}

		if ( ! empty( $job['closing_date'] ) ) {
			$data['validThrough'] = gmdate( 'Y-m-d', strtotime( $job['closing_date'] ) );
		}

		if ( ! empty( $job['company_name'] ) ) {
			$org = array(
				'@type' => 'Organization',
				'name'  => wp_strip_all_tags( $job['company_name'] ),
			);
			if ( ! empty( $job['company_website'] ) ) {
				$org['sameAs'] = esc_url_raw( $job['company_website'] );
			}
			$data['hiringOrganization'] = $org;
		}

		if ( ! empty( $job['remote_type'] ) && 'remote' === $job['remote_type'] ) {
			$data['jobLocationType'] = 'TELECOMMUTE';
		}

		if ( ! empty( $job['location'] ) || ! empty( $job['city'] ) || ! empty( $job['country'] ) ) {
			$address = array( '@type' => 'PostalAddress' );
			if ( ! empty( $job['city'] ) ) {
				$address['addressLocality'] = $job['city'];
			}
			if ( ! empty( $job['region'] ) ) {
				$address['addressRegion'] = $job['region'];
			}
			if ( ! empty( $job['postcode'] ) ) {
				$address['postalCode'] = $job['postcode'];
			}
			if ( ! empty( $job['country_code'] ) ) {
				$address['addressCountry'] = $job['country_code'];
			}
			$data['jobLocation'] = array(
				'@type'   => 'Place',
				'address' => $address,
			);
		}

		if ( ! empty( $job['employment_type'] ) ) {
			$map = array(
				'full-time' => 'FULL_TIME',
				'full time' => 'FULL_TIME',
				'part-time' => 'PART_TIME',
				'part time' => 'PART_TIME',
				'contract'  => 'CONTRACTOR',
				'temporary' => 'TEMPORARY',
				'internship' => 'INTERN',
				'locum'     => 'CONTRACTOR',
			);
			$key = strtolower( $job['employment_type'] );
			if ( isset( $map[ $key ] ) ) {
				$data['employmentType'] = $map[ $key ];
			}
		}

		if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) {
			if ( ! empty( $job['salary_currency'] ) ) {
				$value = array( '@type' => 'QuantitativeValue' );
				if ( ! empty( $job['salary_min'] ) ) {
					$value['minValue'] = (float) $job['salary_min'];
				}
				if ( ! empty( $job['salary_max'] ) ) {
					$value['maxValue'] = (float) $job['salary_max'];
				}
				$value['unitText'] = 'YEAR';

				$data['baseSalary'] = array(
					'@type'    => 'MonetaryAmount',
					'currency' => $job['salary_currency'],
					'value'    => $value,
				);
			}
		}

		if ( ! empty( $job['source_url'] ) ) {
			$data['url'] = esc_url_raw( $job['source_url'] );
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
