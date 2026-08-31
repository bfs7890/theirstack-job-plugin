<?php
/**
 * Import History view.
 *
 * @package HealthcareJobs
 * @var array $history
 * @var int   $total
 */

defined( 'ABSPATH' ) || exit;

$per_page   = 20;
$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$current    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap healthcare-jobs-wrap">
	<h1><?php esc_html_e( 'Import History', 'healthcare-jobs' ); ?></h1>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Started', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Finished', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Trigger', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Status', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Found', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Imported', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Updated', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Skipped', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Expired', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Errors', 'healthcare-jobs' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $history ) ) : ?>
				<tr><td colspan="10"><?php esc_html_e( 'No import history yet.', 'healthcare-jobs' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $history as $row ) : ?>
					<?php $errors = ! empty( $row['errors'] ) ? json_decode( $row['errors'], true ) : array(); ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row['started_at'], 'Y-m-d H:i:s' ) ); ?></td>
						<td><?php echo $row['finished_at'] ? esc_html( get_date_from_gmt( $row['finished_at'], 'Y-m-d H:i:s' ) ) : '—'; ?></td>
						<td><?php echo esc_html( ucfirst( $row['trigger_type'] ) ); ?></td>
						<td><span class="healthcare-jobs-badge healthcare-jobs-badge-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( ucfirst( $row['status'] ) ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $row['jobs_found'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['jobs_imported'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['jobs_updated'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['jobs_skipped'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['jobs_expired'] ) ); ?></td>
						<td>
							<?php if ( ! empty( $errors ) ) : ?>
								<details>
									<summary><?php echo esc_html( sprintf( _n( '%d error', '%d errors', count( $errors ), 'healthcare-jobs' ), count( $errors ) ) ); ?></summary>
									<ul>
										<?php foreach ( array_slice( $errors, 0, 25 ) as $error ) : ?>
											<li><?php echo esc_html( $error ); ?></li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $current,
						'total'     => $total_pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
