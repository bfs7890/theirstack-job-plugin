/**
 * Inserts a "Company Website" button next to Directorist's Bookmark/Share
 * buttons on a single Jobs listing page.
 *
 * Directorist's single-listing header markup isn't controlled by this
 * plugin and varies by theme/version, so rather than assume a fixed DOM
 * structure or hook name, this finds the rendered Bookmark (or Share)
 * button by its visible label and inserts our button immediately before
 * it, inside the same action row.
 */
( function () {
	'use strict';

	// Ordered most-specific first: "Bookmark" is Directorist's own label
	// for this action and unlikely to appear elsewhere on a job listing
	// page, so it's tried before the more generic "Share".
	var ACTION_LABEL_PATTERNS = [ /^bookmark$/i, /^favou?rite$/i, /^save$/i, /^share$/i ];

	function findActionButton() {
		var candidates = document.querySelectorAll( 'a, button' );
		for ( var p = 0; p < ACTION_LABEL_PATTERNS.length; p++ ) {
			for ( var i = 0; i < candidates.length; i++ ) {
				var text = ( candidates[ i ].textContent || '' ).trim();
				if ( ACTION_LABEL_PATTERNS[ p ].test( text ) ) {
					return candidates[ i ];
				}
			}
		}
		return null;
	}

	function buildButton( data ) {
		var link = document.createElement( 'a' );
		link.href = data.websiteUrl;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.className = 'healthcare-jobs-website-btn';
		link.innerHTML =
			'<svg class="healthcare-jobs-website-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
			'<circle cx="12" cy="12" r="10"></circle>' +
			'<line x1="2" y1="12" x2="22" y2="12"></line>' +
			'<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>' +
			'</svg><span></span>';
		link.querySelector( 'span' ).textContent = data.label;
		return link;
	}

	function insertButton( data ) {
		if ( document.querySelector( '.healthcare-jobs-website-btn' ) ) {
			return true;
		}

		var actionButton = findActionButton();
		if ( ! actionButton || ! actionButton.parentElement ) {
			return false;
		}

		actionButton.parentElement.insertBefore( buildButton( data ), actionButton );
		return true;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof HealthcareJobsSingleListing === 'undefined' || ! HealthcareJobsSingleListing.websiteUrl ) {
			return;
		}

		if ( insertButton( HealthcareJobsSingleListing ) ) {
			return;
		}

		// Some themes render Directorist's single-listing header after
		// DOMContentLoaded (client-side templating) - keep watching briefly
		// rather than assuming the first pass found nothing because there's
		// genuinely nothing there.
		var observer = new MutationObserver( function () {
			if ( insertButton( HealthcareJobsSingleListing ) ) {
				observer.disconnect();
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );

		setTimeout( function () {
			observer.disconnect();
		}, 8000 );
	} );
} )();
