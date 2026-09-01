<?php
/**
 * Minimal stand-in for the parts of Directorist this plugin depends on.
 *
 * The real Directorist plugin is not installed in the PHPUnit environment,
 * so this registers just enough of its schema - the `at_biz_dir` post type,
 * the `atbdp_listing_types` / `at_biz_dir-category` / `at_biz_dir-location`
 * taxonomies, and a representative set of terms mirroring what was
 * confirmed on the real site via WP-CLI - for Healthcare_Jobs_Directorist_
 * Mapper/Sync/Classifier to be exercised end-to-end in tests without a
 * hard dependency on the Directorist plugin itself.
 *
 * @package HealthcareJobs
 */

/**
 * Registers the stub post type/taxonomies and seeds test terms. Hooked to
 * `muplugins_loaded` ahead of the plugin itself loading, so Healthcare_
 * Jobs_Database::install() (which runs on the `plugins_loaded` that follows)
 * finds a real `at_biz_dir-category` taxonomy to resolve its default rules
 * against, exactly as it would on a site with Directorist actually active.
 *
 * @return void
 */
function _healthcare_jobs_register_directorist_stub() {
	register_post_type(
		'at_biz_dir',
		array(
			'label'  => 'Listings',
			'public' => true,
		)
	);

	register_taxonomy(
		'atbdp_listing_types',
		'at_biz_dir',
		array( 'label' => 'Listing Types', 'hierarchical' => false )
	);
	register_taxonomy(
		'at_biz_dir-category',
		'at_biz_dir',
		array( 'label' => 'Categories', 'hierarchical' => true )
	);
	register_taxonomy(
		'at_biz_dir-location',
		'at_biz_dir',
		array( 'label' => 'Locations', 'hierarchical' => true )
	);

	if ( ! term_exists( 'jobs', 'atbdp_listing_types' ) ) {
		wp_insert_term( 'Jobs', 'atbdp_listing_types', array( 'slug' => 'jobs' ) );
	}

	// Every category slug Healthcare_Jobs_Categories::get_default_data()
	// expects to find - mirrors the real ~60-term taxonomy confirmed on the
	// live site closely enough for classification tests to be meaningful.
	$category_slugs = array(
		'doctors', 'general-practitioners-gp', 'consultants', 'specialty-doctors',
		'resident-medical-officers', 'nurses', 'advanced-nurse-practitioners',
		'healthcare-assistants', 'midwives', 'pharmacists', 'dentists',
		'optometrists', 'paramedics', 'psychologists', 'psychiatrists',
		'dietitians', 'occupational-therapists', 'physiotherapists',
		'podiatrists', 'radiographers', 'speech-and-language-therapist',
		'counsellors', 'mental-health-nurses', 'psychotherapists',
		'care-assistants', 'care-home-managers', 'domiciliary-care-workers',
		'registered-managers', 'senior-carers', 'supported-living-managers',
		'dental-hygienists', 'dental-nurses', 'orthodontists',
		'pharmacy-assistants', 'pharmacy-technicians', 'compliance-officers',
		'medical-secretaries', 'practice-managers', 'receptionists',
		'biomedical-scientists', 'phlebotomists', 'radiologists',
		'sonographers', 'expert-witnesses', 'independent-medical-examiners',
	);

	foreach ( $category_slugs as $slug ) {
		if ( ! term_exists( $slug, 'at_biz_dir-category' ) ) {
			$name = ucwords( str_replace( '-', ' ', $slug ) );
			wp_insert_term( $name, 'at_biz_dir-category', array( 'slug' => $slug ) );
		}
	}

	$location_slugs = array(
		'united-kingdom' => 'United Kingdom',
		'london'         => 'London',
		'south-east'     => 'South East',
		'midlands'       => 'Midlands',
		'north-west'     => 'North West',
		'scotland'       => 'Scotland',
		'wales'          => 'Wales',
		'saudi-arabia'   => 'Saudi Arabia',
		'turkey'         => 'Turkey',
		'united-arab-emirates' => 'United Arab Emirates',
	);
	foreach ( $location_slugs as $slug => $name ) {
		if ( ! term_exists( $slug, 'at_biz_dir-location' ) ) {
			wp_insert_term( $name, 'at_biz_dir-location', array( 'slug' => $slug ) );
		}
	}

	// Point the mapper's real-site-specific term IDs (269, 203-212, etc.) at
	// whatever IDs this stub actually created, via the same filters an
	// admin would use to adapt the plugin to a different Directorist setup.
	add_filter(
		'healthcare_jobs_directorist_listing_type_id',
		function () {
			$term = get_term_by( 'slug', 'jobs', 'atbdp_listing_types' );
			return $term ? $term->term_id : 0;
		}
	);
	add_filter(
		'healthcare_jobs_directorist_uk_location_id',
		function () {
			$term = get_term_by( 'slug', 'united-kingdom', 'at_biz_dir-location' );
			return $term ? $term->term_id : 0;
		}
	);
	add_filter(
		'healthcare_jobs_directorist_location_map',
		function () {
			$map = array();
			foreach ( array( 'london' => 'london', 'manchester' => 'north-west', 'birmingham' => 'midlands', 'edinburgh' => 'scotland', 'cardiff' => 'wales', 'brighton' => 'south-east' ) as $keyword => $slug ) {
				$term = get_term_by( 'slug', $slug, 'at_biz_dir-location' );
				if ( $term ) {
					$map[ $keyword ] = $term->term_id;
				}
			}
			return $map;
		}
	);
	add_filter(
		'healthcare_jobs_directorist_country_location_map',
		function () {
			$map = array();
			foreach ( array( 'SA' => 'saudi-arabia', 'TR' => 'turkey', 'AE' => 'united-arab-emirates' ) as $code => $slug ) {
				$term = get_term_by( 'slug', $slug, 'at_biz_dir-location' );
				if ( $term ) {
					$map[ $code ] = $term->term_id;
				}
			}
			return $map;
		}
	);
}
