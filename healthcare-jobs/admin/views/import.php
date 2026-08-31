<?php
/**
 * Import Jobs view.
 *
 * @package HealthcareJobs
 * @var array $settings
 * @var array $categories
 * @var bool  $is_locked
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap healthcare-jobs-wrap">
	<h1><?php esc_html_e( 'Import Jobs', 'healthcare-jobs' ); ?></h1>

	<?php if ( ! Healthcare_Jobs_Settings::has_api_key() ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Add a TheirStack API key in Settings before importing.', 'healthcare-jobs' ); ?></p></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Imports run against the settings below. To change frequency or defaults permanently, use the Settings page.', 'healthcare-jobs' ); ?></p>

	<table class="widefat striped healthcare-jobs-summary-table">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Country', 'healthcare-jobs' ); ?></th>
				<td><?php echo esc_html( $settings['default_country'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Job Age', 'healthcare-jobs' ); ?></th>
				<td><?php echo esc_html( sprintf( _n( '%d day', '%d days', $settings['default_job_age_days'], 'healthcare-jobs' ), $settings['default_job_age_days'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Maximum Jobs', 'healthcare-jobs' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $settings['max_jobs_per_import'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Job Status', 'healthcare-jobs' ); ?></th>
				<td><?php echo 'open' === $settings['default_job_status'] ? esc_html__( 'Open jobs only', 'healthcare-jobs' ) : esc_html__( 'Open and closed jobs', 'healthcare-jobs' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Categories Searched', 'healthcare-jobs' ); ?></th>
				<td>
					<?php
					if ( empty( $categories ) ) {
						echo '<em>' . esc_html__( 'No categories configured.', 'healthcare-jobs' ) . '</em>';
					} else {
						echo esc_html( implode( ', ', wp_list_pluck( $categories, 'name' ) ) );
					}
					?>
					&mdash; <a href="<?php echo esc_url( admin_url( 'admin.php?page=healthcare-jobs-categories' ) ); ?>"><?php esc_html_e( 'edit', 'healthcare-jobs' ); ?></a>
				</td>
			</tr>
		</tbody>
	</table>

	<p>
		<button type="button" class="button button-primary button-hero" id="healthcare-jobs-run-import" <?php disabled( $is_locked ); ?>>
			<?php echo $is_locked ? esc_html__( 'Import Already Running…', 'healthcare-jobs' ) : esc_html__( 'Import Now', 'healthcare-jobs' ); ?>
		</button>
	</p>

	<div id="healthcare-jobs-import-progress" class="healthcare-jobs-import-progress" hidden>
		<p class="spinner is-active" style="float:none;"></p>
		<p id="healthcare-jobs-import-status"></p>
	</div>

	<div id="healthcare-jobs-import-result"></div>
</div>
