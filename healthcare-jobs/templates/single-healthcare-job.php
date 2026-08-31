<?php
/**
 * Single job page template, loaded via template_include for the
 * /healthcare-jobs/{slug}/ rewrite. Uses the active theme's header/footer
 * so the page inherits site branding without the plugin depending on any
 * particular theme.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

$slug = get_query_var( Healthcare_Jobs_SEO::QUERY_VAR );
$job  = $slug ? Healthcare_Jobs_Jobs::get_by_slug( $slug ) : null;

if ( ! $job ) {
	status_header( 404 );
}

Healthcare_Jobs_Shortcode::enqueue_assets();

get_header();
?>

<main id="healthcare-jobs-single" class="healthcare-jobs-single-wrap">
	<?php if ( ! $job ) : ?>
		<div class="healthcare-jobs-container">
			<h1><?php esc_html_e( 'Job Not Found', 'healthcare-jobs' ); ?></h1>
			<p><?php esc_html_e( 'This job may have been removed or the link is incorrect.', 'healthcare-jobs' ); ?></p>
			<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return home', 'healthcare-jobs' ); ?></a></p>
		</div>
	<?php else : ?>
		<?php include HEALTHCARE_JOBS_PLUGIN_DIR . 'public/views/job-detail.php'; ?>
	<?php endif; ?>
</main>

<?php
get_footer();
