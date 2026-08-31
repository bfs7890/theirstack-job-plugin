/**
 * Healthcare Jobs admin screen interactions: Test API Connection and
 * Import Now, both via AJAX with a nonce so no privileged action can be
 * triggered by a forged request.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $testButton = $( '#healthcare-jobs-test-connection' );
		var $testResult = $( '#healthcare-jobs-test-result' );

		$testButton.on( 'click', function () {
			$testButton.prop( 'disabled', true );
			$testResult.removeClass( 'success error' ).text( HealthcareJobsAdmin.i18n.testing );

			$.post( HealthcareJobsAdmin.ajaxUrl, {
				action: 'healthcare_jobs_test_connection',
				nonce: HealthcareJobsAdmin.nonce
			} ).done( function ( response ) {
				if ( response.success ) {
					$testResult.addClass( 'success' ).text( response.data.message );
				} else {
					$testResult.addClass( 'error' ).text( response.data.message );
				}
			} ).fail( function () {
				$testResult.addClass( 'error' ).text( 'Request failed. Please try again.' );
			} ).always( function () {
				$testButton.prop( 'disabled', false );
			} );
		} );

		var $importButton = $( '#healthcare-jobs-run-import' );
		var $progress = $( '#healthcare-jobs-import-progress' );
		var $status = $( '#healthcare-jobs-import-status' );
		var $result = $( '#healthcare-jobs-import-result' );

		$importButton.on( 'click', function () {
			$importButton.prop( 'disabled', true );
			$result.empty();
			$progress.prop( 'hidden', false );
			$status.text( HealthcareJobsAdmin.i18n.importing );

			$.post( HealthcareJobsAdmin.ajaxUrl, {
				action: 'healthcare_jobs_run_import',
				nonce: HealthcareJobsAdmin.nonce
			} ).done( function ( response ) {
				$progress.prop( 'hidden', true );
				if ( response.success ) {
					var stats = response.data.stats || {};
					$result.html(
						'<div class="notice notice-success"><p>' +
						'Found: ' + ( stats.jobs_found || 0 ) +
						' &middot; Imported: ' + ( stats.jobs_imported || 0 ) +
						' &middot; Updated: ' + ( stats.jobs_updated || 0 ) +
						' &middot; Skipped: ' + ( stats.jobs_skipped || 0 ) +
						' &middot; Expired: ' + ( stats.jobs_expired || 0 ) +
						'</p></div>'
					);
				} else {
					$result.html( '<div class="notice notice-error"><p>' + response.data.message + '</p></div>' );
					$importButton.prop( 'disabled', false );
				}
			} ).fail( function () {
				$progress.prop( 'hidden', true );
				$result.html( '<div class="notice notice-error"><p>Import request failed. Please try again.</p></div>' );
				$importButton.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
