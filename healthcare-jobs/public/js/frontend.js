/**
 * Healthcare Jobs frontend search: progressive enhancement over the plain
 * HTML form. Vanilla JS only, no framework, scoped so multiple
 * [healthcare_jobs] shortcodes can coexist on one page.
 */
( function () {
	'use strict';

	function serializeForm( form ) {
		var params = new URLSearchParams();
		var elements = form.elements;
		for ( var i = 0; i < elements.length; i++ ) {
			var el = elements[ i ];
			if ( ! el.name || el.disabled ) {
				continue;
			}
			params.append( el.name, el.value );
		}
		return params;
	}

	function loadResults( block, page ) {
		var form = block.querySelector( '[data-healthcare-jobs-form]' );
		var resultsWrap = block.querySelector( '.healthcare-jobs-results-wrap' );
		if ( ! form || ! resultsWrap ) {
			return;
		}

		var params = serializeForm( form );
		params.set( 'action', 'healthcare_jobs_search' );
		params.set( 'paged', page || 1 );
		params.set( 'per_page', resultsWrap.getAttribute( 'data-per-page' ) || 20 );

		resultsWrap.classList.add( 'healthcare-jobs-loading' );

		fetch( HealthcareJobsPublic.ajaxUrl + '?' + params.toString(), {
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && typeof json.data.html === 'string' ) {
					resultsWrap.innerHTML = json.data.html;
					bindPagination( block );
					resultsWrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} )
			.catch( function () {
				// Leave existing results in place on network failure.
			} )
			.finally( function () {
				resultsWrap.classList.remove( 'healthcare-jobs-loading' );
			} );
	}

	function bindPagination( block ) {
		var buttons = block.querySelectorAll( '.healthcare-jobs-page-btn' );
		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				loadResults( block, button.getAttribute( 'data-page' ) );
			} );
		} );
	}

	function bindBlock( block ) {
		var form = block.querySelector( '[data-healthcare-jobs-form]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				loadResults( block, 1 );
			} );

			// Auto-apply dropdown filters without requiring an extra click.
			var selects = form.querySelectorAll( 'select' );
			selects.forEach( function ( select ) {
				select.addEventListener( 'change', function () {
					loadResults( block, 1 );
				} );
			} );
		}
		bindPagination( block );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof HealthcareJobsPublic === 'undefined' ) {
			return;
		}
		var blocks = document.querySelectorAll( '[data-healthcare-jobs]' );
		blocks.forEach( bindBlock );
	} );
} )();
