<?php
/**
 * Ad sizes (IAB standard units) and which of them the site has turned on.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

final class Sizes {

	public const FORMAT_BANNER    = 'banner';
	public const FORMAT_RECTANGLE = 'rectangle';
	public const FORMAT_VERTICAL  = 'vertical';
	public const FORMAT_POPUP     = 'popup';

	/**
	 * Every size the plugin knows about.
	 *
	 * @return array<string, array{label: string, width: int, height: int, format: string}>
	 */
	public static function all(): array {
		$sizes = array(
			// Banners: header, footer, sticky.
			'leaderboard'         => array( 'label' => __( 'Leaderboard', 'ad-network' ), 'width' => 728, 'height' => 90, 'format' => self::FORMAT_BANNER ),
			'large_leaderboard'   => array( 'label' => __( 'Large leaderboard', 'ad-network' ), 'width' => 970, 'height' => 90, 'format' => self::FORMAT_BANNER ),
			'billboard'           => array( 'label' => __( 'Billboard', 'ad-network' ), 'width' => 970, 'height' => 250, 'format' => self::FORMAT_BANNER ),
			'mobile_banner'       => array( 'label' => __( 'Mobile banner', 'ad-network' ), 'width' => 320, 'height' => 50, 'format' => self::FORMAT_BANNER ),
			'large_mobile_banner' => array( 'label' => __( 'Large mobile banner', 'ad-network' ), 'width' => 320, 'height' => 100, 'format' => self::FORMAT_BANNER ),
			// Squares and rectangles: in-content, sidebar.
			'medium_rectangle'    => array( 'label' => __( 'Medium rectangle', 'ad-network' ), 'width' => 300, 'height' => 250, 'format' => self::FORMAT_RECTANGLE ),
			'large_rectangle'     => array( 'label' => __( 'Large rectangle', 'ad-network' ), 'width' => 336, 'height' => 280, 'format' => self::FORMAT_RECTANGLE ),
			'square'              => array( 'label' => __( 'Square', 'ad-network' ), 'width' => 250, 'height' => 250, 'format' => self::FORMAT_RECTANGLE ),
			'small_square'        => array( 'label' => __( 'Small square', 'ad-network' ), 'width' => 200, 'height' => 200, 'format' => self::FORMAT_RECTANGLE ),
			// Tall sidebar units.
			'half_page'           => array( 'label' => __( 'Half page', 'ad-network' ), 'width' => 300, 'height' => 600, 'format' => self::FORMAT_VERTICAL ),
			'wide_skyscraper'     => array( 'label' => __( 'Wide skyscraper', 'ad-network' ), 'width' => 160, 'height' => 600, 'format' => self::FORMAT_VERTICAL ),
			// Popups (served from phase 3).
			'popup_large'         => array( 'label' => __( 'Large popup', 'ad-network' ), 'width' => 600, 'height' => 500, 'format' => self::FORMAT_POPUP ),
			'popup_small'         => array( 'label' => __( 'Small popup', 'ad-network' ), 'width' => 400, 'height' => 300, 'format' => self::FORMAT_POPUP ),
		);

		/**
		 * Add or change ad sizes.
		 *
		 * @param array<string, array{label: string, width: int, height: int, format: string}> $sizes
		 */
		return (array) apply_filters( 'adn_sizes', $sizes );
	}

	/** @return array<string, string> Format key => label. */
	public static function formats(): array {
		return array(
			self::FORMAT_BANNER    => __( 'Banners', 'ad-network' ),
			self::FORMAT_RECTANGLE => __( 'Squares and rectangles', 'ad-network' ),
			self::FORMAT_VERTICAL  => __( 'Tall sidebar units', 'ad-network' ),
			self::FORMAT_POPUP     => __( 'Popups', 'ad-network' ),
		);
	}

	/** Sizes on by default on a new install. */
	public static function defaultEnabled(): array {
		return array( 'leaderboard', 'mobile_banner', 'medium_rectangle', 'large_rectangle', 'square', 'half_page' );
	}

	public static function exists( string $key ): bool {
		return isset( self::all()[ $key ] );
	}

	/** @return array{label: string, width: int, height: int, format: string}|null */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/** @return array<string, array{label: string, width: int, height: int, format: string}> */
	public static function enabled(): array {
		$on = (array) Settings::get( 'sizes' );
		return array_intersect_key( self::all(), array_flip( $on ) );
	}

	public static function isEnabled( string $key ): bool {
		return isset( self::enabled()[ $key ] );
	}

	/** "Medium rectangle (300×250)". */
	public static function label( string $key ): string {
		$size = self::get( $key );
		if ( ! $size ) {
			return $key;
		}
		return sprintf( '%s (%d×%d)', $size['label'], $size['width'], $size['height'] );
	}
}
