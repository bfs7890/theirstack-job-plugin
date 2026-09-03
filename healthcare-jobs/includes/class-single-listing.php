<?php
/**
 * Adds a "Company Website" button to Directorist's native single-listing
 * page for Jobs listings, next to its Bookmark/Share actions, using each
 * job's own company website URL.
 *
 * Directorist owns the single-listing template entirely (see the note in
 * healthcare-jobs.php) - this plugin has no template of its own to hook a
 * button into, and its exact header markup/action hooks vary by theme and
 * Directorist version. Rather than guess a do_action() name that may not
 * exist on this install, a small script finds Directorist's own rendered
 * Bookmark/Share buttons in the DOM and inserts our button next to them,
 * which works regardless of the underlying template.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Single_Listing {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enqueues the button's assets only on a Jobs listing's single page,
	 * and only when that listing actually has a company website set.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! is_singular( 'at_biz_dir' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		// Scoped to the "Jobs" listing type only, same as every other query
		// in this plugin - a shared Directorist install may have other
		// listing types (e.g. clinics, courses) that this button must never
		// appear on.
		if ( ! has_term( Healthcare_Jobs_Directorist_Mapper::get_job_listing_type_term_id(), Healthcare_Jobs_Search::LISTING_TYPE_TAXONOMY, $post_id ) ) {
			return;
		}

		// '_custom-url' is the Directorist field this plugin's mapper writes
		// the job's company website into - see
		// Healthcare_Jobs_Directorist_Mapper::map().
		$website = get_post_meta( $post_id, '_custom-url', true );
		if ( empty( $website ) ) {
			return;
		}

		wp_enqueue_style( 'healthcare-jobs-single-listing', HEALTHCARE_JOBS_PLUGIN_URL . 'public/css/single-listing.css', array(), HEALTHCARE_JOBS_VERSION );
		wp_enqueue_script( 'healthcare-jobs-single-listing', HEALTHCARE_JOBS_PLUGIN_URL . 'public/js/single-listing.js', array(), HEALTHCARE_JOBS_VERSION, true );
		wp_localize_script(
			'healthcare-jobs-single-listing',
			'HealthcareJobsSingleListing',
			array(
				'websiteUrl' => esc_url_raw( $website ),
				'label'      => __( 'Company Website', 'healthcare-jobs' ),
			)
		);
	}
}
