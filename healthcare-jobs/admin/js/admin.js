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
					var status = response.data.status || 'success';
					var noticeClass = 'failed' === status ? 'notice-error' : ( 'partial' === status ? 'notice-warning' : 'notice-success' );

					var $notice = $( '<div class="notice"><p></p></div>' ).addClass( noticeClass );
					$notice.find( 'p' ).text(
						'Found: ' + ( stats.jobs_found || 0 ) +
						' · Imported: ' + ( stats.jobs_imported || 0 ) +
						' · Updated: ' + ( stats.jobs_updated || 0 ) +
						' · Skipped: ' + ( stats.jobs_skipped || 0 ) +
						' · Expired: ' + ( stats.jobs_expired || 0 )
					);

					var errors = response.data.errors || [];
					if ( errors.length ) {
						var $details = $( '<details><summary></summary></details>' );
						$details.find( 'summary' ).text( errors.length + ' message(s) from this import' );
						var $list = $( '<ul></ul>' );
						errors.forEach( function ( message ) {
							$( '<li></li>' ).text( message ).appendTo( $list );
						} );
						$details.append( $list );
						$notice.append( $details );
					}

					$result.empty().append( $notice );
				} else {
					$result.html( '<div class="notice notice-error"><p></p></div>' ).find( 'p' ).text( response.data.message );
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
