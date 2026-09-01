<?php
/**
 * Single job card partial.
 *
 * @package HealthcareJobs
 * @var array $job
 */

defined( 'ABSPATH' ) || exit;

$job_url = $job['permalink'];

$salary_text = '';
if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) {
	$currency_symbol = array(
		'GBP' => '£',
		'USD' => '$',
		'EUR' => '€',
	);
	$symbol = isset( $currency_symbol[ $job['salary_currency'] ] ) ? $currency_symbol[ $job['salary_currency'] ] : ( $job['salary_currency'] ? $job['salary_currency'] . ' ' : '' );

	if ( ! empty( $job['salary_min'] ) && ! empty( $job['salary_max'] ) ) {
		$salary_text = sprintf( '%1$s%2$s - %1$s%3$s', $symbol, number_format_i18n( $job['salary_min'] ), number_format_i18n( $job['salary_max'] ) );
	} elseif ( ! empty( $job['salary_min'] ) ) {
		$salary_text = sprintf( '%1$s%2$s+', $symbol, number_format_i18n( $job['salary_min'] ) );
	} elseif ( ! empty( $job['salary_max'] ) ) {
		$salary_text = sprintf( __( 'Up to %1$s%2$s', 'healthcare-jobs' ), $symbol, number_format_i18n( $job['salary_max'] ) );
	}
}
?>
<article class="healthcare-jobs-card">
	<h3 class="healthcare-jobs-card-title">
		<a href="<?php echo esc_url( $job_url ); ?>"><?php echo esc_html( $job['title'] ); ?></a>
	</h3>
	<div class="healthcare-jobs-card-meta">
		<?php if ( ! empty( $job['company_name'] ) ) : ?>
			<span class="healthcare-jobs-card-company"><?php echo esc_html( $job['company_name'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $job['location'] ) ) : ?>
			<span class="healthcare-jobs-card-location"><?php echo esc_html( $job['location'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $job['employment_type'] ) ) : ?>
			<span class="healthcare-jobs-card-badge"><?php echo esc_html( $job['employment_type'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $job['remote_type'] ) ) : ?>
			<span class="healthcare-jobs-card-badge"><?php echo esc_html( ucfirst( $job['remote_type'] ) ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $job['category'] ) ) : ?>
			<span class="healthcare-jobs-card-badge healthcare-jobs-card-badge-category"><?php echo esc_html( $job['category'] ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( $salary_text ) : ?>
		<div class="healthcare-jobs-card-salary"><?php echo esc_html( $salary_text ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $job['excerpt'] ) ) : ?>
		<p class="healthcare-jobs-card-excerpt"><?php echo esc_html( wp_strip_all_tags( $job['excerpt'] ) ); ?>&hellip;</p>
	<?php endif; ?>

	<div class="healthcare-jobs-card-footer">
		<?php if ( ! empty( $job['posted_at'] ) ) : ?>
			<span class="healthcare-jobs-card-date">
				<?php
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( 'Posted %s ago', 'healthcare-jobs' ),
					esc_html( human_time_diff( strtotime( $job['posted_at'] ), current_time( 'timestamp', true ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				);
				?>
			</span>
		<?php endif; ?>
		<a href="<?php echo esc_url( $job_url ); ?>" class="healthcare-jobs-card-apply"><?php esc_html_e( 'View & Apply', 'healthcare-jobs' ); ?></a>
	</div>
</article>
