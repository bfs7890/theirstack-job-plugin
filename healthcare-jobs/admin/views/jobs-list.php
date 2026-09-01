<?php
/**
 * Admin Jobs list view.
 *
 * @package HealthcareJobs
 * @var array  $result
 * @var array  $categories
 * @var string $search
 * @var string $status
 * @var string $category
 * @var int    $paged
 */

defined( 'ABSPATH' ) || exit;

$per_page    = 20;
$total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );

$base_url = admin_url( 'admin.php?page=healthcare-jobs-list' );
?>
<div class="wrap healthcare-jobs-wrap">
	<h1><?php esc_html_e( 'Healthcare Jobs', 'healthcare-jobs' ); ?></h1>

	<form method="get" class="healthcare-jobs-filters">
		<input type="hidden" name="page" value="healthcare-jobs-list" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search title, company, location…', 'healthcare-jobs' ); ?>" />
		<select name="status">
			<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All Statuses', 'healthcare-jobs' ); ?></option>
			<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'healthcare-jobs' ); ?></option>
			<option value="closed" <?php selected( $status, 'closed' ); ?>><?php esc_html_e( 'Closed', 'healthcare-jobs' ); ?></option>
			<option value="expired" <?php selected( $status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'healthcare-jobs' ); ?></option>
		</select>
		<select name="category">
			<option value=""><?php esc_html_e( 'All Categories', 'healthcare-jobs' ); ?></option>
			<?php foreach ( $categories as $cat_name ) : ?>
				<option value="<?php echo esc_attr( $cat_name ); ?>" <?php selected( $category, $cat_name ); ?>><?php echo esc_html( $cat_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filter', 'healthcare-jobs' ), 'secondary', 'submit', false ); ?>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Company', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Location', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Category', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Posted', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Status', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Source', 'healthcare-jobs' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'healthcare-jobs' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['items'] ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No jobs found.', 'healthcare-jobs' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['items'] as $job ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $job['title'] ); ?></strong></td>
					<td><?php echo esc_html( $job['company_name'] ); ?></td>
					<td><?php echo esc_html( $job['location'] ); ?></td>
					<td><?php echo esc_html( $job['category'] ); ?></td>
					<td><?php echo $job['posted_at'] ? esc_html( get_date_from_gmt( $job['posted_at'], 'Y-m-d' ) ) : '—'; ?></td>
					<td><span class="healthcare-jobs-badge healthcare-jobs-badge-<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( 'publish' === $job['status'] ? __( 'Active', 'healthcare-jobs' ) : ucfirst( $job['status'] ) ); ?></span></td>
					<td><?php echo esc_html( ucfirst( $job['source'] ) ); ?></td>
					<td class="healthcare-jobs-row-actions">
						<?php if ( ! empty( $job['source_url'] ) ) : ?>
							<a href="<?php echo esc_url( $job['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Source', 'healthcare-jobs' ); ?></a> |
						<?php endif; ?>
						<a href="<?php echo esc_url( $job['permalink'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'healthcare-jobs' ); ?></a> |

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="healthcare_jobs_job_action" />
							<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['id'] ); ?>" />
							<?php wp_nonce_field( Healthcare_Jobs_Admin::NONCE_ACTION ); ?>
							<?php if ( 'publish' === $job['status'] ) : ?>
								<button type="submit" name="job_action" value="deactivate" class="button-link"><?php esc_html_e( 'Deactivate', 'healthcare-jobs' ); ?></button>
							<?php else : ?>
								<button type="submit" name="job_action" value="activate" class="button-link"><?php esc_html_e( 'Activate', 'healthcare-jobs' ); ?></button>
							<?php endif; ?>
							|
							<button type="submit" name="job_action" value="delete" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this job record?', 'healthcare-jobs' ) ); ?>');"><?php esc_html_e( 'Delete', 'healthcare-jobs' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => max( 1, $paged ),
						'total'   => $total_pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
