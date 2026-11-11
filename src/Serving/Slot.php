<?php
/**
 * An empty, correctly sized slot the loader fills in the browser.
 *
 * Slots are rendered empty on purpose: page caches store this HTML, so the ad itself is chosen
 * per visitor by the REST call in assets/frontend.js. The reserved width/height stops the page
 * jumping when the ad arrives.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Serving;

use AdNetwork\Placements;
use AdNetwork\Settings;
use AdNetwork\Sizes;

final class Slot {

	/** Whether any slot was printed on this page (so the loader script is needed). */
	private static bool $used = false;

	/**
	 * Markup for a configured placement, or '' if it's off.
	 */
	public static function forPlacement( string $placement ): string {
		$config = Placements::config( $placement );
		if ( ! $config || empty( $config['enabled'] ) ) {
			return '';
		}
		return self::markup( $placement, (string) $config['desktop'], (string) $config['mobile'] );
	}

	/**
	 * Markup from template tag / shortcode / widget arguments: a placement, or explicit sizes.
	 *
	 * @param array<string, mixed> $args placement, size, mobile.
	 */
	public static function fromArgs( array $args ): string {
		$placement = sanitize_key( (string) ( $args['placement'] ?? '' ) );
		$size      = sanitize_key( (string) ( $args['size'] ?? '' ) );

		if ( '' === $size ) {
			return '' !== $placement ? self::forPlacement( $placement ) : '';
		}

		// Explicit sizes: mobile defaults to the same size; "none" hides it on mobile.
		$mobile = array_key_exists( 'mobile', $args ) ? sanitize_key( (string) $args['mobile'] ) : $size;
		return self::markup( '' !== $placement ? $placement : 'manual', $size, 'none' === $mobile ? '' : $mobile );
	}

	public static function markup( string $placement, string $desktop, string $mobile ): string {
		$d = Sizes::isEnabled( $desktop ) ? Sizes::get( $desktop ) : null;
		$m = '' !== $mobile && Sizes::isEnabled( $mobile ) ? Sizes::get( $mobile ) : null;
		if ( ! $d && ! $m ) {
			return '';
		}

		self::$used = true;

		$style = array();
		$data  = array();
		if ( $d ) {
			$style[] = sprintf( '--adn-dw:%dpx;--adn-dh:%dpx', $d['width'], $d['height'] );
			$data[]  = sprintf( 'data-adn-desktop="%s"', esc_attr( $desktop ) );
		}
		if ( $m ) {
			$style[] = sprintf( '--adn-mw:%dpx;--adn-mh:%dpx', $m['width'], $m['height'] );
			$data[]  = sprintf( 'data-adn-mobile="%s"', esc_attr( $mobile ) );
		}

		$classes = array( 'adn-slot', 'adn-slot--' . sanitize_html_class( $placement ) );
		if ( ! $d ) {
			$classes[] = 'adn-slot--no-desktop';
		}
		if ( ! $m ) {
			$classes[] = 'adn-slot--no-mobile';
		}

		$label = (string) Settings::get( 'label' );

		return sprintf(
			'<div class="%s" data-adn-placement="%s" %s style="%s"%s><div class="adn-slot__ad"></div></div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $placement ),
			implode( ' ', $data ),
			esc_attr( implode( ';', $style ) ),
			'' !== $label ? ' data-adn-label="' . esc_attr( $label ) . '"' : ''
		);
	}

	public static function used(): bool {
		return self::$used;
	}
}
