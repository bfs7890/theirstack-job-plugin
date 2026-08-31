<?php
/**
 * Settings view.
 *
 * @package HealthcareJobs
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$key_from_constant = Healthcare_Jobs_Settings::api_key_is_from_constant();
$masked_key         = Healthcare_Jobs_Settings::get_masked_api_key();
?>
<div class="wrap healthcare-jobs-wrap">
	<h1><?php esc_html_e( 'Healthcare Jobs Settings', 'healthcare-jobs' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="healthcare_jobs_save_settings" />
		<?php wp_nonce_field( Healthcare_Jobs_Admin::NONCE_ACTION ); ?>

		<h2><?php esc_html_e( 'TheirStack API', 'healthcare-jobs' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="api_key"><?php esc_html_e( 'TheirStack API Key', 'healthcare-jobs' ); ?></label></th>
				<td>
					<?php if ( $key_from_constant ) : ?>
						<p>
							<code><?php esc_html_e( 'Defined via HEALTHCARE_JOBS_THEIRSTACK_API_KEY in wp-config.php', 'healthcare-jobs' ); ?></code>
						</p>
						<p class="description"><?php esc_html_e( 'Remove the constant from wp-config.php to manage the key from this screen instead.', 'healthcare-jobs' ); ?></p>
					<?php else : ?>
						<input type="password" id="api_key" name="api_key" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( $masked_key ? $masked_key : __( 'Enter your TheirStack API key', 'healthcare-jobs' ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Stored encrypted in the database. Leave blank to keep the current key unchanged. For maximum security, define HEALTHCARE_JOBS_THEIRSTACK_API_KEY in wp-config.php instead.', 'healthcare-jobs' ); ?>
						</p>
					<?php endif; ?>
					<p>
						<button type="button" class="button" id="healthcare-jobs-test-connection"><?php esc_html_e( 'Test API Connection', 'healthcare-jobs' ); ?></button>
						<span id="healthcare-jobs-test-result" class="healthcare-jobs-test-result"></span>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Import Defaults', 'healthcare-jobs' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="default_country"><?php esc_html_e( 'Default Country', 'healthcare-jobs' ); ?></label></th>
				<td>
					<input type="text" id="default_country" name="default_country" value="<?php echo esc_attr( $settings['default_country'] ); ?>" maxlength="2" class="small-text" />
					<p class="description"><?php esc_html_e( 'ISO 3166-1 alpha-2 country code, e.g. GB for United Kingdom.', 'healthcare-jobs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="default_job_age_days"><?php esc_html_e( 'Default Job Age (days)', 'healthcare-jobs' ); ?></label></th>
				<td><input type="number" id="default_job_age_days" name="default_job_age_days" value="<?php echo esc_attr( $settings['default_job_age_days'] ); ?>" min="1" max="365" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="max_jobs_per_import"><?php esc_html_e( 'Maximum Jobs Per Import', 'healthcare-jobs' ); ?></label></th>
				<td><input type="number" id="max_jobs_per_import" name="max_jobs_per_import" value="<?php echo esc_attr( $settings['max_jobs_per_import'] ); ?>" min="1" max="5000" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default Job Status', 'healthcare-jobs' ); ?></th>
				<td>
					<select name="default_job_status">
						<option value="open" <?php selected( $settings['default_job_status'], 'open' ); ?>><?php esc_html_e( 'Open jobs only', 'healthcare-jobs' ); ?></option>
						<option value="all" <?php selected( $settings['default_job_status'], 'all' ); ?>><?php esc_html_e( 'Open and closed jobs', 'healthcare-jobs' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="results_per_page"><?php esc_html_e( 'Search Results Per Page', 'healthcare-jobs' ); ?></label></th>
				<td><input type="number" id="results_per_page" name="results_per_page" value="<?php echo esc_attr( $settings['results_per_page'] ); ?>" min="5" max="100" class="small-text" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Automatic Import', 'healthcare-jobs' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic Import Enabled', 'healthcare-jobs' ); ?></th>
				<td><label><input type="checkbox" name="auto_import_enabled" value="1" <?php checked( $settings['auto_import_enabled'], 1 ); ?> /> <?php esc_html_e( 'Run imports automatically via WP-Cron', 'healthcare-jobs' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="import_frequency"><?php esc_html_e( 'Import Frequency', 'healthcare-jobs' ); ?></label></th>
				<td>
					<select id="import_frequency" name="import_frequency">
						<?php foreach ( Healthcare_Jobs_Cron::get_schedule_choices() as $key => $data ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['import_frequency'], $key ); ?>><?php echo esc_html( $data['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Uninstall', 'healthcare-jobs' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete Data on Uninstall', 'healthcare-jobs' ); ?></th>
				<td>
					<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'], 1 ); ?> /> <?php esc_html_e( 'Permanently delete all jobs, companies, categories, and import history when this plugin is deleted from Plugins.', 'healthcare-jobs' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off by default: deactivating or deleting the plugin normally leaves your imported data untouched.', 'healthcare-jobs' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'healthcare-jobs' ) ); ?>
	</form>
</div>
