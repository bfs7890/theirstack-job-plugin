<?php
/**
 * Single job detail markup.
 *
 * @package HealthcareJobs
 * @var array $job
 */

defined( 'ABSPATH' ) || exit;

$salary_text = '';
if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) {
	$currency_symbol = array(
		'GBP' => '£',
		'USD' => '$',
		'EUR' => '€',
	);
	$symbol = isset( $currency_symbol[ $job['salary_currency'] ] ) ? $currency_symbol[ $job['salary_currency'] ] : ( $job['salary_currency'] ? $job['salary_currency'] . ' ' : '' );

	if ( ! empty( $job['salary_min'] ) && ! empty( $job['salary_max'] ) ) {
		$salary_text = sprintf( '%1$s%2$s - %1$s%3$s per year', $symbol, number_format_i18n( $job['salary_min'] ), number_format_i18n( $job['salary_max'] ) );
	} elseif ( ! empty( $job['salary_min'] ) ) {
		$salary_text = sprintf( '%1$s%2$s+ per year', $symbol, number_format_i18n( $job['salary_min'] ) );
	} elseif ( ! empty( $job['salary_max'] ) ) {
		$salary_text = sprintf( __( 'Up to %1$s%2$s per year', 'healthcare-jobs' ), $symbol, number_format_i18n( $job['salary_max'] ) );
	}
}

$is_inactive = 'active' !== $job['status'];
?>
<div class="healthcare-jobs-container healthcare-jobs-single">

	<?php if ( $is_inactive ) : ?>
		<div class="healthcare-jobs-notice healthcare-jobs-notice-closed">
			<?php esc_html_e( 'This vacancy is no longer accepting applications. It is shown for reference only.', 'healthcare-jobs' ); ?>
		</div>
	<?php endif; ?>

	<header class="healthcare-jobs-single-header">
		<h1><?php echo esc_html( $job['title'] ); ?></h1>
		<div class="healthcare-jobs-single-meta">
			<?php if ( ! empty( $job['company_name'] ) ) : ?>
				<span class="healthcare-jobs-single-company"><?php echo esc_html( $job['company_name'] ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $job['location'] ) ) : ?>
				<span class="healthcare-jobs-single-location"><?php echo esc_html( $job['location'] ); ?></span>
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
			<div class="healthcare-jobs-single-salary"><?php echo esc_html( $salary_text ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $job['posted_at'] ) ) : ?>
			<div class="healthcare-jobs-single-date">
				<?php
				printf(
					/* translators: %s: date the job was posted */
					esc_html__( 'Posted on %s', 'healthcare-jobs' ),
					esc_html( get_date_from_gmt( $job['posted_at'], get_option( 'date_format' ) ) )
				);
				?>
			</div>
		<?php endif; ?>
	</header>

	<div class="healthcare-jobs-notice healthcare-jobs-notice-source">
		<?php
		printf(
			/* translators: %s: original source name, e.g. the employer's careers site */
			esc_html__( 'This vacancy is aggregated from an external source (%s). Applications are handled entirely by the employer.', 'healthcare-jobs' ),
			esc_html( ucfirst( $job['source'] ) )
		);
		?>
	</div>

	<?php if ( ! $is_inactive && ! empty( $job['source_url'] ) ) : ?>
		<a href="<?php echo esc_url( $job['source_url'] ); ?>" class="healthcare-jobs-apply-btn" target="_blank" rel="noopener noreferrer nofollow sponsored">
			<?php esc_html_e( 'Apply Now on Employer Site', 'healthcare-jobs' ); ?>
		</a>
	<?php endif; ?>

	<div class="healthcare-jobs-single-section">
		<h2><?php esc_html_e( 'Job Description', 'healthcare-jobs' ); ?></h2>
		<div class="healthcare-jobs-single-content"><?php echo wp_kses_post( wpautop( $job['description'] ) ); ?></div>
	</div>

	<?php if ( ! empty( $job['requirements'] ) ) : ?>
		<div class="healthcare-jobs-single-section">
			<h2><?php esc_html_e( 'Requirements', 'healthcare-jobs' ); ?></h2>
			<div class="healthcare-jobs-single-content"><?php echo wp_kses_post( wpautop( $job['requirements'] ) ); ?></div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $job['benefits'] ) ) : ?>
		<div class="healthcare-jobs-single-section">
			<h2><?php esc_html_e( 'Benefits', 'healthcare-jobs' ); ?></h2>
			<div class="healthcare-jobs-single-content"><?php echo wp_kses_post( wpautop( $job['benefits'] ) ); ?></div>
		</div>
	<?php endif; ?>

	<?php if ( ! $is_inactive && ! empty( $job['source_url'] ) ) : ?>
		<a href="<?php echo esc_url( $job['source_url'] ); ?>" class="healthcare-jobs-apply-btn" target="_blank" rel="noopener noreferrer nofollow sponsored">
			<?php esc_html_e( 'Apply Now on Employer Site', 'healthcare-jobs' ); ?>
		</a>
	<?php endif; ?>

	<p class="healthcare-jobs-back-link"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">&larr; <?php esc_html_e( 'Back to job search', 'healthcare-jobs' ); ?></a></p>
</div>
