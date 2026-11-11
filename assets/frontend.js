/**
 * Ad Network loader.
 *
 * Pages are cached, so slots arrive empty. This script asks the server which ad goes in each
 * slot (one request per page), drops the ads in, and reports views (at least half the ad on
 * screen for one second) and clicks back using the signed token that came with each ad.
 */
( function () {
	'use strict';

	var config = window.adnConfig || {};
	if ( ! config.endpoint || ! window.fetch ) {
		return;
	}

	var VIEW_RATIO = 0.5;
	var VIEW_MS = 1000;
	var counter = 0;
	var observer = null;

	function isMobile() {
		return window.innerWidth < ( config.breakpoint || 768 );
	}

	function post( path, body, useNonce ) {
		var headers = { 'Content-Type': 'application/json' };
		if ( useNonce && config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}
		return fetch( config.endpoint + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( body ),
		} ).then( function ( res ) {
			// An expired nonce makes WordPress refuse the request; ask again anonymously.
			if ( 403 === res.status && useNonce && config.nonce ) {
				return post( path, body, false );
			}
			return res.ok ? res.json() : { ads: {} };
		} );
	}

	function report( token, event ) {
		if ( ! token ) {
			return;
		}
		var url = config.endpoint + '/event';
		var data = new URLSearchParams( { t: token, e: event } );
		if ( navigator.sendBeacon && navigator.sendBeacon( url, data ) ) {
			return;
		}
		fetch( url, { method: 'POST', body: data, keepalive: true, credentials: 'same-origin' } ).catch( function () {} );
	}

	/** innerHTML never runs <script> tags; recreate them so third-party ad code works. */
	function runScripts( container ) {
		var scripts = container.querySelectorAll( 'script' );
		Array.prototype.forEach.call( scripts, function ( old ) {
			var fresh = document.createElement( 'script' );
			Array.prototype.forEach.call( old.attributes, function ( attr ) {
				fresh.setAttribute( attr.name, attr.value );
			} );
			fresh.text = old.text;
			old.parentNode.replaceChild( fresh, old );
		} );
	}

	function watchViews( slot ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			report( slot.adnToken, 'view' );
			return;
		}
		if ( ! observer ) {
			observer = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					var el = entry.target;
					if ( entry.isIntersecting && entry.intersectionRatio >= VIEW_RATIO ) {
						if ( ! el.adnTimer ) {
							el.adnTimer = setTimeout( function () {
								if ( 'visible' === document.visibilityState ) {
									observer.unobserve( el );
									report( el.adnToken, 'view' );
								}
								el.adnTimer = null;
							}, VIEW_MS );
						}
					} else if ( el.adnTimer ) {
						clearTimeout( el.adnTimer );
						el.adnTimer = null;
					}
				} );
			}, { threshold: [ 0, VIEW_RATIO ] } );
		}
		observer.observe( slot );
	}

	function watchClicks( slot ) {
		var onClick = function ( event ) {
			if ( 'auxclick' === event.type && 1 !== event.button ) {
				return;
			}
			if ( event.target.closest && event.target.closest( 'a[href]' ) ) {
				report( slot.adnToken, 'click' );
			}
		};
		slot.addEventListener( 'click', onClick );
		slot.addEventListener( 'auxclick', onClick );
	}

	function fill( slot, ad ) {
		var box = slot.querySelector( '.adn-slot__ad' ) || slot;
		box.innerHTML = ad.html;
		if ( ad.thirdParty ) {
			runScripts( box );
		}
		slot.adnToken = ad.token;
		slot.setAttribute( 'data-adn-ad', String( ad.ad ) );
		slot.classList.add( 'adn-slot--filled' );
		if ( ad.token ) {
			watchViews( slot );
			watchClicks( slot );
		}
	}

	function load( root ) {
		var mobile = isMobile();
		var slots = ( root || document ).querySelectorAll( '.adn-slot:not([data-adn-id])' );
		var request = [];
		var byId = {};

		Array.prototype.forEach.call( slots, function ( slot ) {
			var size = slot.getAttribute( mobile ? 'data-adn-mobile' : 'data-adn-desktop' );
			var id = 's' + ( ++counter );
			slot.setAttribute( 'data-adn-id', id );
			if ( ! size ) {
				return; // Hidden at this screen size.
			}
			byId[ id ] = slot;
			request.push( { id: id, size: size, placement: slot.getAttribute( 'data-adn-placement' ) || '' } );
		} );

		if ( ! request.length ) {
			return Promise.resolve();
		}

		return post( '/serve', { page: config.pageId || 0, slots: request }, true )
			.then( function ( data ) {
				var ads = ( data && data.ads ) || {};
				Object.keys( byId ).forEach( function ( id ) {
					if ( ads[ id ] ) {
						fill( byId[ id ], ads[ id ] );
					} else {
						byId[ id ].classList.add( 'adn-slot--empty' );
					}
				} );
			} )
			.catch( function () {
				Object.keys( byId ).forEach( function ( id ) {
					byId[ id ].classList.add( 'adn-slot--empty' );
				} );
			} );
	}

	// Let themes and builders load slots they add after the page has loaded.
	window.adnLoad = load;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			load();
		} );
	} else {
		load();
	}
}() );
