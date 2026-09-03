<?php
/**
 * WordPress admin interface: menus, form handlers, and AJAX endpoints.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Admin {

	const NONCE_ACTION = 'healthcare_jobs_admin';

	/**
	 * Wires up all admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		add_action( 'admin_post_healthcare_jobs_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_healthcare_jobs_add_category', array( __CLASS__, 'handle_add_category' ) );
		add_action( 'admin_post_healthcare_jobs_delete_category', array( __CLASS__, 'handle_delete_category' ) );
		add_action( 'admin_post_healthcare_jobs_add_title', array( __CLASS__, 'handle_add_title' ) );
		add_action( 'admin_post_healthcare_jobs_delete_title', array( __CLASS__, 'handle_delete_title' ) );
		add_action( 'admin_post_healthcare_jobs_job_action', array( __CLASS__, 'handle_job_action' ) );

		add_action( 'wp_ajax_healthcare_jobs_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_healthcare_jobs_test_adzuna_connection', array( __CLASS__, 'ajax_test_adzuna_connection' ) );
		add_action( 'wp_ajax_healthcare_jobs_run_import', array( __CLASS__, 'ajax_run_import' ) );
		add_action( 'wp_ajax_healthcare_jobs_run_migration', array( __CLASS__, 'ajax_run_migration' ) );
	}

	/**
	 * Registers the top-level menu and all submenus.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$cap = Healthcare_Jobs_Settings::CAPABILITY;

		add_menu_page(
			__( 'Healthcare Jobs', 'healthcare-jobs' ),
			__( 'Healthcare Jobs', 'healthcare-jobs' ),
			$cap,
			'healthcare-jobs',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-heart',
			26
		);

		add_submenu_page( 'healthcare-jobs', __( 'Dashboard', 'healthcare-jobs' ), __( 'Dashboard', 'healthcare-jobs' ), $cap, 'healthcare-jobs', array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( 'healthcare-jobs', __( 'Jobs', 'healthcare-jobs' ), __( 'Jobs', 'healthcare-jobs' ), $cap, 'healthcare-jobs-list', array( __CLASS__, 'render_jobs_list' ) );
		add_submenu_page( 'healthcare-jobs', __( 'Import Jobs', 'healthcare-jobs' ), __( 'Import Jobs', 'healthcare-jobs' ), $cap, 'healthcare-jobs-import', array( __CLASS__, 'render_import' ) );
		add_submenu_page( 'healthcare-jobs', __( 'Import History', 'healthcare-jobs' ), __( 'Import History', 'healthcare-jobs' ), $cap, 'healthcare-jobs-history', array( __CLASS__, 'render_history' ) );
		add_submenu_page( 'healthcare-jobs', __( 'Categories', 'healthcare-jobs' ), __( 'Categories', 'healthcare-jobs' ), $cap, 'healthcare-jobs-categories', array( __CLASS__, 'render_categories' ) );
		add_submenu_page( 'healthcare-jobs', __( 'Settings', 'healthcare-jobs' ), __( 'Settings', 'healthcare-jobs' ), $cap, 'healthcare-jobs-settings', array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * True on any of our admin screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	private static function is_our_screen( $hook ) {
		return false !== strpos( (string) $hook, 'healthcare-jobs' );
	}

	/**
	 * Loads admin CSS/JS only on our own screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! self::is_our_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style( 'healthcare-jobs-admin', HEALTHCARE_JOBS_PLUGIN_URL . 'admin/css/admin.css', array(), HEALTHCARE_JOBS_VERSION );
		wp_enqueue_script( 'healthcare-jobs-admin', HEALTHCARE_JOBS_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery' ), HEALTHCARE_JOBS_VERSION, true );
		wp_localize_script(
			'healthcare-jobs-admin',
			'HealthcareJobsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'testing'  => __( 'Testing connection…', 'healthcare-jobs' ),
					'importing' => __( 'Importing… this can take a minute.', 'healthcare-jobs' ),
				),
			)
		);
	}

	/**
	 * Renders queued admin notices from a redirect (transient-backed so
	 * they survive the redirect after a form POST).
	 *
	 * @return void
	 */
	public static function render_notices() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			return;
		}
		$notice = get_transient( 'healthcare_jobs_admin_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'healthcare_jobs_admin_notice_' . get_current_user_id() );

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Stores a one-time admin notice and redirects back to a settings page.
	 *
	 * @param string $page    Submenu slug to return to.
	 * @param string $type    'success' or 'error'.
	 * @param string $message Notice text.
	 * @return void
	 */
	private static function redirect_with_notice( $page, $type, $message ) {
		set_transient( 'healthcare_jobs_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	/**
	 * Verifies capability + nonce for a standard admin-post form submission.
	 *
	 * @param string $nonce_name POST field holding the nonce.
	 * @return void Dies on failure.
	 */
	private static function guard( $nonce_name = '_wpnonce' ) {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'healthcare-jobs' ), 403 );
		}
		if ( ! isset( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'healthcare-jobs' ), 403 );
		}
	}

	/* ---------------------------------------------------------------
	 * View renderers
	 * ------------------------------------------------------------- */

	public static function render_dashboard() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			return;
		}
		$stats        = Healthcare_Jobs_Jobs::get_stats();
		$company_count = $stats['company_count'];
		$last_import  = Healthcare_Jobs_Logger::get_last_import();
		$migration_pending = Healthcare_Jobs_Migration::count_pending();
		$next_run     = Healthcare_Jobs_Cron::get_next_run();
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	public static function render_jobs_list() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			return;
		}
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		$result     = Healthcare_Jobs_Jobs::admin_query(
			array(
				'search'   => $search,
				'status'   => $status,
				'category' => $category,
				'paged'    => $paged,
				'per_page' => 20,
			)
		);
		$categories = Healthcare_Jobs_Categories::get_names();

		include HEALTHCARE_JOBS_PLUGIN_DIR . 'admin/views/jobs-list.php';
	}

	public static function render_import() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			return;
		}
		$settings   = Healthcare_Jobs_Settings::get_all();
		$categories = Healthcare_Jobs_Categories::get_all();
		$is_locked  = Healthcare_Jobs_Importer::is_locked();
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'admin/views/import.php';
	}

	public static function render_history() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			return;
		}
		$paged   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$per_page = 20;
		$history = Healthcare_Jobs_Logger::get_history( $per_page, ( max( 1, $paged ) - 1 ) * $per_page );
		$total   = Healthcare_Jobs_Logger::count_history();
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'admin/views/import-history.php';
	}

	public static function render_categories() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			return;
		}
		$categories = Healthcare_Jobs_Categories::get_all();
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'admin/views/categories.php';
	}

	public static function render_settings() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			return;
		}
		$settings = Healthcare_Jobs_Settings::get_all();
		include HEALTHCARE_JOBS_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/* ---------------------------------------------------------------
	 * Form handlers (admin-post.php)
	 * ------------------------------------------------------------- */

	public static function handle_save_settings() {
		self::guard();
		Healthcare_Jobs_Settings::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		self::redirect_with_notice( 'healthcare-jobs-settings', 'success', __( 'Settings saved.', 'healthcare-jobs' ) );
	}

	public static function handle_add_category() {
		self::guard();
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$result = Healthcare_Jobs_Categories::add_category( $name );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'healthcare-jobs-categories', 'error', $result->get_error_message() );
		}
		self::redirect_with_notice( 'healthcare-jobs-categories', 'success', __( 'Category added.', 'healthcare-jobs' ) );
	}

	public static function handle_delete_category() {
		self::guard();
		$id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		if ( $id ) {
			Healthcare_Jobs_Categories::delete_category( $id );
		}
		self::redirect_with_notice( 'healthcare-jobs-categories', 'success', __( 'Category deleted.', 'healthcare-jobs' ) );
	}

	public static function handle_add_title() {
		self::guard();
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$result      = Healthcare_Jobs_Categories::add_title( $category_id, $title );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'healthcare-jobs-categories', 'error', $result->get_error_message() );
		}
		self::redirect_with_notice( 'healthcare-jobs-categories', 'success', __( 'Job title added.', 'healthcare-jobs' ) );
	}

	public static function handle_delete_title() {
		self::guard();
		$id = isset( $_POST['title_id'] ) ? absint( $_POST['title_id'] ) : 0;
		if ( $id ) {
			Healthcare_Jobs_Categories::delete_title( $id );
		}
		self::redirect_with_notice( 'healthcare-jobs-categories', 'success', __( 'Job title deleted.', 'healthcare-jobs' ) );
	}

	public static function handle_job_action() {
		self::guard();

		$id     = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$action = isset( $_POST['job_action'] ) ? sanitize_key( $_POST['job_action'] ) : '';

		if ( ! $id || ! $action ) {
			self::redirect_with_notice( 'healthcare-jobs-list', 'error', __( 'Invalid request.', 'healthcare-jobs' ) );
		}

		switch ( $action ) {
			case 'deactivate':
				Healthcare_Jobs_Jobs::set_status( $id, Healthcare_Jobs_Directorist_Mapper::get_closed_post_status() );
				$message = __( 'Job deactivated.', 'healthcare-jobs' );
				break;
			case 'activate':
				Healthcare_Jobs_Jobs::set_status( $id, Healthcare_Jobs_Jobs::STATUS_ACTIVE );
				$message = __( 'Job reactivated.', 'healthcare-jobs' );
				break;
			case 'delete':
				Healthcare_Jobs_Jobs::delete( $id );
				$message = __( 'Job deleted.', 'healthcare-jobs' );
				break;
			default:
				self::redirect_with_notice( 'healthcare-jobs-list', 'error', __( 'Unknown action.', 'healthcare-jobs' ) );
				return;
		}

		self::redirect_with_notice( 'healthcare-jobs-list', 'success', $message );
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	/**
	 * Verifies capability + AJAX nonce, dies with JSON error on failure.
	 *
	 * @return void
	 */
	private static function ajax_guard() {
		if ( ! current_user_can( Healthcare_Jobs_Settings::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'healthcare-jobs' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public static function ajax_test_connection() {
		self::ajax_guard();

		$api    = new Healthcare_Jobs_TheirStack_API();
		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			// Never echo the raw API key back; get_error_message() never
			// contains it since we build messages ourselves in the API class.
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of jobs returned by the minimal test query */
					__( 'Authentication successful (HTTP 200). Minimal query returned %d job(s).', 'healthcare-jobs' ),
					isset( $result['jobs_returned'] ) ? (int) $result['jobs_returned'] : 0
				),
			)
		);
	}

	public static function ajax_test_adzuna_connection() {
		self::ajax_guard();

		$api    = new Healthcare_Jobs_Adzuna_API();
		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			// Never echo raw credentials back; get_error_message() never
			// contains them since we build messages ourselves in the API class.
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of jobs returned by the minimal test query */
					__( 'Authentication successful. Minimal query returned %d job(s).', 'healthcare-jobs' ),
					isset( $result['jobs_returned'] ) ? (int) $result['jobs_returned'] : 0
				),
			)
		);
	}

	public static function ajax_run_import() {
		self::ajax_guard();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$importer = new Healthcare_Jobs_Importer();
		$result   = $importer->run( 'manual' );

		if ( Healthcare_Jobs_Settings::adzuna_import_enabled() ) {
			$adzuna_importer = new Healthcare_Jobs_Adzuna_Importer();
			$result          = self::merge_import_results( $result, $adzuna_importer->run( 'manual' ) );
		}

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Combines a TheirStack and an Adzuna import result into the single
	 * summary the "Import Now" button/response already renders: counts are
	 * summed, errors are concatenated (Adzuna's are already "[Adzuna] "
	 * prefixed by Healthcare_Jobs_Adzuna_Importer), and the worse of the
	 * two statuses wins. The combined stage is only ever "authentication"
	 * when neither source got as far as running a search - if TheirStack's
	 * own search ran, an Adzuna auth failure is just one more error in the
	 * list, not a reason to hide TheirStack's real stats behind the
	 * "no search ran" UI.
	 *
	 * @param array $primary Result from Healthcare_Jobs_Importer::run().
	 * @param array $adzuna  Result from Healthcare_Jobs_Adzuna_Importer::run().
	 * @return array
	 */
	private static function merge_import_results( array $primary, array $adzuna ) {
		if ( ! empty( $adzuna['error'] ) ) {
			// Adzuna failed to even start (e.g. its own lock is held) -
			// surface it as an extra error rather than losing it, but don't
			// let it override a TheirStack result that did run.
			if ( ! empty( $primary['error'] ) ) {
				return $primary;
			}
			$primary['errors'][] = '[Adzuna] ' . $adzuna['error'];
			return $primary;
		}

		if ( ! empty( $primary['error'] ) ) {
			return $primary;
		}

		$stats = $primary['stats'];
		foreach ( $adzuna['stats'] as $key => $value ) {
			$stats[ $key ] = ( $stats[ $key ] ?? 0 ) + $value;
		}

		$status_rank = array( 'success' => 0, 'partial' => 1, 'failed' => 2 );
		$primary_status = $primary['status'] ?? 'success';
		$adzuna_status  = $adzuna['status'] ?? 'success';
		$status         = $status_rank[ $primary_status ] >= $status_rank[ $adzuna_status ] ? $primary_status : $adzuna_status;

		$stage = ( 'authentication' === ( $primary['stage'] ?? 'search' ) && 'authentication' === ( $adzuna['stage'] ?? 'search' ) )
			? 'authentication'
			: 'search';

		return array(
			'stats'      => $stats,
			'status'     => $status,
			'errors'     => array_merge( $primary['errors'] ?? array(), $adzuna['errors'] ?? array() ),
			'error_code' => $primary['error_code'] ?? null,
			'stage'      => $stage,
		);
	}

	/**
	 * One-time migration of pre-Directorist jobs (from an install upgraded
	 * from before 2.0.0) into real Directorist listings.
	 *
	 * @return void
	 */
	public static function ajax_run_migration() {
		self::ajax_guard();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		wp_send_json_success( Healthcare_Jobs_Migration::run() );
	}
}
