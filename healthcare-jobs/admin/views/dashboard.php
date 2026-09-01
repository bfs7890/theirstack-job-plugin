<?php
/**
 * Dashboard view.
 *
 * @package HealthcareJobs
 * @var array      $stats
 * @var int        $company_count
 * @var array|null $last_import
 * @var int|false  $next_run
 * @var int        $migration_pending
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap healthcare-jobs-wrap">
	<h1><?php esc_html_e( 'Healthcare Jobs Dashboard', 'healthcare-jobs' ); ?></h1>

	<?php if ( $migration_pending > 0 ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %d: number of jobs pending migration */
					esc_html( _n( '%d job imported before this plugin version can be migrated into Directorist listings.', '%d jobs imported before this plugin version can be migrated into Directorist listings.', $migration_pending, 'healthcare-jobs' ) ),
					(int) $migration_pending
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="healthcare-jobs-run-migration"><?php esc_html_e( 'Migrate Existing Jobs to Directorist', 'healthcare-jobs' ); ?></button>
			</p>
			<div id="healthcare-jobs-migration-result"></div>
		</div>
	<?php endif; ?>

	<?php if ( ! Healthcare_Jobs_Settings::has_api_key() ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: link to settings page */
					esc_html__( 'No TheirStack API key is configured yet. %s to start importing jobs.', 'healthcare-jobs' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=healthcare-jobs-settings' ) ) . '">' . esc_html__( 'Add your API key', 'healthcare-jobs' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="healthcare-jobs-stat-grid">
		<div class="healthcare-jobs-stat-card">
			<span class="stat-number"><?php echo esc_html( number_format_i18n( $stats['active_jobs'] ) ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Active Jobs', 'healthcare-jobs' ); ?></span>
		</div>
		<div class="healthcare-jobs-stat-card">
			<span class="stat-number"><?php echo esc_html( number_format_i18n( $stats['new_today'] ) ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'New Today', 'healthcare-jobs' ); ?></span>
		</div>
		<div class="healthcare-jobs-stat-card">
			<span class="stat-number"><?php echo esc_html( number_format_i18n( $stats['new_this_week'] ) ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'New This Week', 'healthcare-jobs' ); ?></span>
		</div>
		<div class="healthcare-jobs-stat-card">
			<span class="stat-number"><?php echo esc_html( number_format_i18n( $company_count ) ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Companies', 'healthcare-jobs' ); ?></span>
		</div>
		<div class="healthcare-jobs-stat-card">
			<span class="stat-number"><?php echo esc_html( number_format_i18n( $stats['expired_jobs'] ) ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Expired Jobs', 'healthcare-jobs' ); ?></span>
		</div>
	</div>

	<h2><?php esc_html_e( 'Last Import', 'healthcare-jobs' ); ?></h2>
	<table class="widefat striped healthcare-jobs-summary-table">
		<tbody>
			<?php if ( $last_import ) : ?>
				<tr>
					<th><?php esc_html_e( 'Date', 'healthcare-jobs' ); ?></th>
					<td><?php echo esc_html( get_date_from_gmt( $last_import['started_at'], 'Y-m-d H:i' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'healthcare-jobs' ); ?></th>
					<td><span class="healthcare-jobs-badge healthcare-jobs-badge-<?php echo esc_attr( $last_import['status'] ); ?>"><?php echo esc_html( ucfirst( $last_import['status'] ) ); ?></span></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Jobs Found / Imported / Updated', 'healthcare-jobs' ); ?></th>
					<td>
						<?php
						printf(
							'%1$s / %2$s / %3$s',
							esc_html( number_format_i18n( $last_import['jobs_found'] ) ),
							esc_html( number_format_i18n( $last_import['jobs_imported'] ) ),
							esc_html( number_format_i18n( $last_import['jobs_updated'] ) )
						);
						?>
					</td>
				</tr>
			<?php else : ?>
				<tr><td><?php esc_html_e( 'No imports have run yet.', 'healthcare-jobs' ); ?></td></tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Next Scheduled Import', 'healthcare-jobs' ); ?></th>
				<td>
					<?php
					if ( $next_run ) {
						echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_run ), 'Y-m-d H:i' ) );
					} else {
						esc_html_e( 'Automatic imports are disabled.', 'healthcare-jobs' );
					}
					?>
				</td>
			</tr>
		</tbody>
	</table>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=healthcare-jobs-import' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Import Jobs', 'healthcare-jobs' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=healthcare-jobs-history' ) ); ?>" class="button"><?php esc_html_e( 'View Import History', 'healthcare-jobs' ); ?></a>
	</p>
</div>
