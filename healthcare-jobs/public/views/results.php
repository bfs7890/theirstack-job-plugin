<?php
/**
 * Search results: job card list + pagination. Included both by the
 * initial shortcode render and by the AJAX search handler, so it must not
 * assume anything beyond $result and $filters being set.
 *
 * @package HealthcareJobs
 * @var array $result
 * @var array $filters
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="healthcare-jobs-results-meta">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: number of jobs found */
			_n( '%s healthcare job found', '%s healthcare jobs found', $result['total'], 'healthcare-jobs' ),
			number_format_i18n( $result['total'] )
		)
	);
	?>
</div>

<div class="healthcare-jobs-list">
	<?php if ( empty( $result['items'] ) ) : ?>
		<p class="healthcare-jobs-empty"><?php esc_html_e( 'No jobs match your search. Try broadening your filters.', 'healthcare-jobs' ); ?></p>
	<?php else : ?>
		<?php foreach ( $result['items'] as $job ) : ?>
			<?php include HEALTHCARE_JOBS_PLUGIN_DIR . 'public/views/job-card.php'; ?>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

<?php if ( $result['pages'] > 1 ) : ?>
	<div class="healthcare-jobs-pagination" data-current="<?php echo esc_attr( $filters['paged'] ); ?>" data-total="<?php echo esc_attr( $result['pages'] ); ?>">
		<?php for ( $i = 1; $i <= $result['pages']; $i++ ) : ?>
			<button type="button" class="healthcare-jobs-page-btn<?php echo $i === (int) $filters['paged'] ? ' is-active' : ''; ?>" data-page="<?php echo esc_attr( $i ); ?>"><?php echo esc_html( $i ); ?></button>
		<?php endfor; ?>
	</div>
<?php endif; ?>
