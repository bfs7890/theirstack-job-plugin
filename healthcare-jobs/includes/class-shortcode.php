<?php
/**
 * [healthcare_jobs] shortcode and its AJAX search endpoint.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Shortcode {

	const TAG = 'healthcare_jobs';

	/**
	 * Registers the shortcode, its AJAX endpoints, and frontend assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );

		// Public, read-only search: safe for logged-out visitors and for
		// pages sitting behind full-page caching (no nonce dependency).
		add_action( 'wp_ajax_healthcare_jobs_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_healthcare_jobs_search', array( __CLASS__, 'ajax_search' ) );
	}

	/**
	 * Registers frontend assets so they carry a version + dependency graph;
	 * actually enqueued only when the shortcode is present on the page.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, self::TAG ) ) {
			return;
		}
		self::enqueue_assets();
	}

	/**
	 * Enqueues the frontend CSS/JS. Called both from the shortcode's own
	 * page-content detection and from the Gutenberg block render.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( 'healthcare-jobs-frontend', HEALTHCARE_JOBS_PLUGIN_URL . 'public/css/frontend.css', array(), HEALTHCARE_JOBS_VERSION );
		wp_enqueue_script( 'healthcare-jobs-frontend', HEALTHCARE_JOBS_PLUGIN_URL . 'public/js/frontend.js', array(), HEALTHCARE_JOBS_VERSION, true );
		wp_localize_script(
			'healthcare-jobs-frontend',
			'HealthcareJobsPublic',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Renders the shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		self::enqueue_assets();

		$atts = shortcode_atts(
			array(
				'category' => '',
				'location' => '',
				'limit'    => 0,
			),
			$atts,
			self::TAG
		);

		$settings = Healthcare_Jobs_Settings::get_all();
		$per_page = ! empty( $atts['limit'] ) ? absint( $atts['limit'] ) : (int) $settings['results_per_page'];

		$filters = array(
			'keyword'         => '',
			'location'        => sanitize_text_field( $atts['location'] ),
			'category'        => sanitize_text_field( $atts['category'] ),
			'specialty'       => '',
			'employment_type' => '',
			'remote_type'     => '',
			'salary_min'      => '',
			'date_posted'     => 'any',
			'paged'           => 1,
			'per_page'        => $per_page,
		);

		$result = Healthcare_Jobs_Search::query( $filters );

		ob_start();
		echo '<div class="healthcare-jobs-block" data-healthcare-jobs>';
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'public/views/search-form.php';
		echo '<div class="healthcare-jobs-results-wrap" data-per-page="' . esc_attr( $per_page ) . '">';
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'public/views/results.php';
		echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * AJAX handler backing the live search/filter form. Reads only from the
	 * local database via Healthcare_Jobs_Search — TheirStack is never
	 * contacted on a visitor request.
	 *
	 * @return void
	 */
	public static function ajax_search() {
		$filters = array(
			'keyword'         => isset( $_GET['keyword'] ) ? sanitize_text_field( wp_unslash( $_GET['keyword'] ) ) : '',
			'location'        => isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '',
			'category'        => isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '',
			'specialty'       => isset( $_GET['specialty'] ) ? sanitize_text_field( wp_unslash( $_GET['specialty'] ) ) : '',
			'employment_type' => isset( $_GET['employment_type'] ) ? sanitize_text_field( wp_unslash( $_GET['employment_type'] ) ) : '',
			'remote_type'     => isset( $_GET['remote_type'] ) ? sanitize_text_field( wp_unslash( $_GET['remote_type'] ) ) : '',
			'salary_min'      => isset( $_GET['salary_min'] ) ? absint( $_GET['salary_min'] ) : 0,
			'date_posted'     => isset( $_GET['date_posted'] ) ? sanitize_key( $_GET['date_posted'] ) : 'any',
			'paged'           => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
			'per_page'        => isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : (int) Healthcare_Jobs_Settings::get( 'results_per_page', 20 ),
		);

		$result = Healthcare_Jobs_Search::query( $filters );

		ob_start();
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'public/views/results.php';
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'  => $html,
				'total' => $result['total'],
				'pages' => $result['pages'],
			)
		);
	}
}
