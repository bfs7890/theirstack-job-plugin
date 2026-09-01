<?php
/**
 * Frontend search/filter form for the [healthcare_jobs] shortcode.
 *
 * @package HealthcareJobs
 * @var array $filters
 */

defined( 'ABSPATH' ) || exit;

$categories       = Healthcare_Jobs_Search::get_top_level_categories();
$specialties      = Healthcare_Jobs_Search::get_specialty_terms();
$employment_types = Healthcare_Jobs_Search::get_filter_options( 'employment_type' );
$remote_types     = Healthcare_Jobs_Search::get_filter_options( 'remote_type' );
?>
<form class="healthcare-jobs-search-form" data-healthcare-jobs-form>
	<div class="healthcare-jobs-search-row">
		<input type="text" name="keyword" class="healthcare-jobs-input" placeholder="<?php esc_attr_e( 'Job title, company, or keyword', 'healthcare-jobs' ); ?>" value="<?php echo esc_attr( $filters['keyword'] ); ?>" />
		<input type="text" name="location" class="healthcare-jobs-input" placeholder="<?php esc_attr_e( 'Location', 'healthcare-jobs' ); ?>" value="<?php echo esc_attr( $filters['location'] ); ?>" />
		<button type="submit" class="healthcare-jobs-search-btn"><?php esc_html_e( 'Search Jobs', 'healthcare-jobs' ); ?></button>
	</div>
	<div class="healthcare-jobs-filter-row">
		<select name="category">
			<option value=""><?php esc_html_e( 'All Categories', 'healthcare-jobs' ); ?></option>
			<?php foreach ( $categories as $term ) : ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( (string) $filters['category'], (string) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="specialty">
			<option value=""><?php esc_html_e( 'All Specialties', 'healthcare-jobs' ); ?></option>
			<?php foreach ( $specialties as $term ) : ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( (string) $filters['specialty'], (string) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="employment_type">
			<option value=""><?php esc_html_e( 'Any Employment Type', 'healthcare-jobs' ); ?></option>
			<?php foreach ( $employment_types as $name ) : ?>
				<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['employment_type'], $name ); ?>><?php echo esc_html( $name ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="remote_type">
			<option value=""><?php esc_html_e( 'Any Work Type', 'healthcare-jobs' ); ?></option>
			<?php foreach ( $remote_types as $name ) : ?>
				<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['remote_type'], $name ); ?>><?php echo esc_html( ucfirst( $name ) ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="salary_min">
			<option value=""><?php esc_html_e( 'Any Salary', 'healthcare-jobs' ); ?></option>
			<?php foreach ( array( 20000, 30000, 40000, 50000, 60000, 80000, 100000 ) as $amount ) : ?>
				<option value="<?php echo esc_attr( $amount ); ?>" <?php selected( (string) $filters['salary_min'], (string) $amount ); ?>>
					<?php echo esc_html( sprintf( __( '£%s+', 'healthcare-jobs' ), number_format_i18n( $amount ) ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="date_posted">
			<?php
			$date_options = array(
				'any' => __( 'Any Time', 'healthcare-jobs' ),
				'24h' => __( 'Last 24 hours', 'healthcare-jobs' ),
				'7d'  => __( 'Last 7 days', 'healthcare-jobs' ),
				'14d' => __( 'Last 14 days', 'healthcare-jobs' ),
				'30d' => __( 'Last 30 days', 'healthcare-jobs' ),
			);
			foreach ( $date_options as $value => $label ) :
				?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['date_posted'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</form>
