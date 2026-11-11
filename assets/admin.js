/* Ad Network: ad edit screen. */
( function ( $ ) {
	'use strict';

	var cfg = window.adnAdmin || {};
	var frame = null;
	var codeEditor = null;

	function toggleType() {
		var type = $( 'input[name="adn[type]"]:checked' ).val() || 'image';
		$( '.adn-type-image' ).toggle( 'image' === type );
		$( '.adn-type-html' ).toggle( 'html' === type );
		if ( 'html' === type && codeEditor ) {
			codeEditor.codemirror.refresh();
		}
	}

	function checkImageSize() {
		var $warn = $( '#adn-image-warning' );
		var w = parseInt( $warn.attr( 'data-width' ), 10 );
		var h = parseInt( $warn.attr( 'data-height' ), 10 );
		var size = ( cfg.sizes || {} )[ $( '#adn-size' ).val() ];
		if ( ! w || ! h || ! size || ( w === size.width && h === size.height ) || ( w * size.height === h * size.width ) ) {
			$warn.text( '' ).hide();
			return;
		}
		$warn.text( cfg.i18n.mismatch.replace( '%1$s', w ).replace( '%2$s', h ).replace( '%3$s', size.width ).replace( '%4$s', size.height ) ).show();
	}

	$( function () {
		if ( cfg.codeEditor && window.wp && wp.codeEditor && $( '#adn-html' ).length ) {
			codeEditor = wp.codeEditor.initialize( 'adn-html', cfg.codeEditor );
		}

		$( 'input[name="adn[type]"]' ).on( 'change', toggleType );
		$( '#adn-size' ).on( 'change', checkImageSize );
		toggleType();
		checkImageSize();

		$( '#adn-image-choose' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! frame ) {
				frame = wp.media( {
					title: cfg.i18n.chooseImage,
					button: { text: cfg.i18n.useImage },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var img = frame.state().get( 'selection' ).first().toJSON();
					$( '#adn-image-id' ).val( img.id );
					$( '#adn-image-preview' ).empty().append( $( '<img>' ).attr( { src: img.url, alt: '' } ) );
					$( '#adn-image-warning' ).attr( { 'data-width': img.width, 'data-height': img.height } );
					if ( ! $( '#adn-alt' ).val() && img.alt ) {
						$( '#adn-alt' ).val( img.alt );
					}
					$( '#adn-image-remove' ).prop( 'hidden', false );
					checkImageSize();
				} );
			}
			frame.open();
		} );

		$( '#adn-image-remove' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( '#adn-image-id' ).val( '' );
			$( '#adn-image-preview' ).empty();
			$( '#adn-image-warning' ).attr( { 'data-width': '', 'data-height': '' } );
			$( this ).prop( 'hidden', true );
			checkImageSize();
		} );
	} );
}( jQuery ) );
