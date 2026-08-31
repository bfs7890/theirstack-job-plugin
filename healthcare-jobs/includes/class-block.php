<?php
/**
 * Optional Gutenberg block wrapping the [healthcare_jobs] shortcode.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Block {

	/**
	 * Registers the block, when the running WordPress version supports
	 * block.json-based registration. Older installs simply keep using the
	 * shortcode, so this never breaks the site either way.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type( HEALTHCARE_JOBS_PLUGIN_DIR . 'blocks/healthcare-jobs' );
	}
}
