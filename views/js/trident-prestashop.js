/**
 * The PrestaShop binding of trident-sections.js: the classic theme's cart
 * count and sign-in link on a shared, cached page. Values are written with
 * textContent — never parsed as HTML.
 */
( function () {
	'use strict';
	// The post-login race: until the visitor's own sections (and form token)
	// have been applied, the page holds the GUEST token — a click on "add to
	// cart" would be silently ignored by PrestaShop. When the stored sections
	// are stale for this visitor's version cookie, keep the buttons disabled
	// until they arrive (at most 5 s).
	var cfg = window.tridentSections || {};
	function cookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}
	var version = ( cfg.versionCookies || [ 'trident_pv' ] ).map( cookie ).join( '|' );
	var stored = '';
	try {
		stored = ( JSON.parse( window.localStorage.getItem( cfg.storageKey || 'trident_sections' ) || '{}' ) || {} ).version || '';
	} catch ( e ) {
		stored = '';
	}
	var waiting = version.replace( /\|/g, '' ) !== '' && stored !== version;
	function buttons( disabled ) {
		var b = document.querySelectorAll( '[data-button-action="add-to-cart"]' );
		for ( var i = 0; i < b.length; i++ ) {
			if ( disabled ) {
				b[ i ].setAttribute( 'data-trident-wait', '1' );
				b[ i ].disabled = true;
			} else if ( b[ i ].getAttribute( 'data-trident-wait' ) ) {
				b[ i ].removeAttribute( 'data-trident-wait' );
				b[ i ].disabled = false;
			}
		}
	}
	if ( waiting ) {
		buttons( true );
		window.setTimeout( function () {
			buttons( false );
		}, 5000 );
	}
	document.addEventListener( 'trident:sections', function ( e ) {
		var s = e.detail || {};
		// Classic 5 (.header-block__badge) and classic 2.x (.cart-products-count).
		var badges = document.querySelectorAll( '.blockcart .header-block__badge' );
		for ( var i = 0; i < badges.length; i++ ) {
			badges[ i ].textContent = String( s.cart_count || 0 );
		}
		var counts = document.querySelectorAll( '.cart-products-count' );
		for ( var k = 0; k < counts.length; k++ ) {
			counts[ k ].textContent = s.cart_count_label || '(0)';
		}
		// The visitor's own form token (the shared page has the guest's).
		if ( s.static_token ) {
			var guest = window.prestashop && window.prestashop.static_token;
			if ( window.prestashop ) {
				window.prestashop.static_token = s.static_token;
			}
			var inputs = document.querySelectorAll( 'input[name="token"]' );
			for ( var t = 0; t < inputs.length; t++ ) {
				if ( ! guest || inputs[ t ].value === guest ) {
					inputs[ t ].value = s.static_token;
				}
			}
		}
		buttons( false );
		var signIn = document.querySelectorAll( 'a.header-block__action-btn[href*="login"], #_desktop_user_info .user-info a[href*="login"], #_mobile_user_info .user-info a[href*="login"]' );
		for ( var j = 0; j < signIn.length; j++ ) {
			signIn[ j ].hidden = !! s.logged_in;
		}
	} );
	// PrestaShop's own AJAX cart: the version cookie rotated, fetch again.
	if ( window.prestashop && typeof window.prestashop.on === 'function' ) {
		window.prestashop.on( 'updateCart', function () {
			if ( window.TridentSections ) {
				window.TridentSections.refresh();
			}
		} );
	}
} )();
