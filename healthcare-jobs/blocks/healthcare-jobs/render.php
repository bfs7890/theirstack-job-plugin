<?php
/**
 * Server-side render for the healthcare-jobs/job-board block. Reuses the
 * exact same rendering path as the [healthcare_jobs] shortcode so the two
 * never drift apart.
 *
 * @package HealthcareJobs
 * @var array $attributes Block attributes (category, location, limit).
 */

defined( 'ABSPATH' ) || exit;

echo Healthcare_Jobs_Shortcode::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	array(
		'category' => isset( $attributes['category'] ) ? $attributes['category'] : '',
		'location' => isset( $attributes['location'] ) ? $attributes['location'] : '',
		'limit'    => isset( $attributes['limit'] ) ? $attributes['limit'] : 0,
	)
);
