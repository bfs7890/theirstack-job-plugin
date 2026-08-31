<?php
/**
 * Tests for WP-Cron scheduling.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Cron_Test extends WP_UnitTestCase {

	public function test_default_schedule_is_registered() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'healthcare_jobs_six_hours', $schedules );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $schedules['healthcare_jobs_six_hours']['interval'] );
	}

	public function test_reschedule_creates_event_when_enabled() {
		Healthcare_Jobs_Settings::save( array( 'auto_import_enabled' => 1, 'import_frequency' => 'six_hours' ) );
		$this->assertIsInt( Healthcare_Jobs_Cron::get_next_run() );
	}

	public function test_reschedule_clears_event_when_disabled() {
		Healthcare_Jobs_Settings::save( array( 'auto_import_enabled' => 1 ) );
		$this->assertNotFalse( Healthcare_Jobs_Cron::get_next_run() );

		Healthcare_Jobs_Settings::save( array( 'auto_import_enabled' => 0 ) );
		$this->assertFalse( Healthcare_Jobs_Cron::get_next_run() );
	}

	public function test_frequency_change_reschedules_with_new_interval() {
		Healthcare_Jobs_Settings::save( array( 'auto_import_enabled' => 1, 'import_frequency' => 'daily' ) );

		$crons = _get_cron_array();
		$found = false;
		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks[ Healthcare_Jobs_Cron::HOOK ] ) ) {
				foreach ( $hooks[ Healthcare_Jobs_Cron::HOOK ] as $event ) {
					if ( 'healthcare_jobs_daily' === $event['schedule'] ) {
						$found = true;
					}
				}
			}
		}
		$this->assertTrue( $found, 'Expected the daily schedule to be applied.' );
	}

	public function test_clear_removes_all_scheduled_events() {
		Healthcare_Jobs_Settings::save( array( 'auto_import_enabled' => 1 ) );
		Healthcare_Jobs_Cron::clear();
		$this->assertFalse( Healthcare_Jobs_Cron::get_next_run() );
	}
}
