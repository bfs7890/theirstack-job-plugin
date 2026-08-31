<?php
/**
 * Categories & job titles configuration view.
 *
 * @package HealthcareJobs
 * @var array $categories
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap healthcare-jobs-wrap">
	<h1><?php esc_html_e( 'Healthcare Categories & Job Titles', 'healthcare-jobs' ); ?></h1>
	<p><?php esc_html_e( 'Configure the categories and job titles used both to search TheirStack and to classify imported jobs. Changes apply to the next import.', 'healthcare-jobs' ); ?></p>

	<h2><?php esc_html_e( 'Add Category', 'healthcare-jobs' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="healthcare-jobs-inline-form">
		<input type="hidden" name="action" value="healthcare_jobs_add_category" />
		<?php wp_nonce_field( Healthcare_Jobs_Admin::NONCE_ACTION ); ?>
		<input type="text" name="name" placeholder="<?php esc_attr_e( 'e.g. Optometry', 'healthcare-jobs' ); ?>" required class="regular-text" />
		<?php submit_button( __( 'Add Category', 'healthcare-jobs' ), 'secondary', 'submit', false ); ?>
	</form>

	<?php foreach ( $categories as $category ) : ?>
		<?php $titles = Healthcare_Jobs_Categories::get_titles_for_category( $category['id'] ); ?>
		<div class="healthcare-jobs-category-box">
			<h3>
				<?php echo esc_html( $category['name'] ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="healthcare-jobs-inline-form-delete" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this category and all its job titles?', 'healthcare-jobs' ) ); ?>');">
					<input type="hidden" name="action" value="healthcare_jobs_delete_category" />
					<input type="hidden" name="category_id" value="<?php echo esc_attr( $category['id'] ); ?>" />
					<?php wp_nonce_field( Healthcare_Jobs_Admin::NONCE_ACTION ); ?>
					<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete category', 'healthcare-jobs' ); ?></button>
				</form>
			</h3>

			<ul class="healthcare-jobs-title-list">
				<?php foreach ( $titles as $title ) : ?>
					<li>
						<?php echo esc_html( $title['title'] ); ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="healthcare-jobs-inline-form-delete">
							<input type="hidden" name="action" value="healthcare_jobs_delete_title" />
							<input type="hidden" name="title_id" value="<?php echo esc_attr( $title['id'] ); ?>" />
							<?php wp_nonce_field( Healthcare_Jobs_Admin::NONCE_ACTION ); ?>
							<button type="submit" class="button-link-delete" aria-label="<?php esc_attr_e( 'Remove title', 'healthcare-jobs' ); ?>">&times;</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="healthcare-jobs-inline-form">
				<input type="hidden" name="action" value="healthcare_jobs_add_title" />
				<input type="hidden" name="category_id" value="<?php echo esc_attr( $category['id'] ); ?>" />
				<?php wp_nonce_field( Healthcare_Jobs_Admin::NONCE_ACTION ); ?>
				<input type="text" name="title" placeholder="<?php esc_attr_e( 'Add a job title…', 'healthcare-jobs' ); ?>" required />
				<?php submit_button( __( 'Add', 'healthcare-jobs' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
	<?php endforeach; ?>
</div>
