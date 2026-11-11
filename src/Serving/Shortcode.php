<?php
/**
 * [adn_slot] shortcode, usable in posts, Etch and any builder that renders shortcodes.
 *
 *   [adn_slot placement="sidebar"]                          Use a placement's settings.
 *   [adn_slot size="medium_rectangle"]                      Any enabled size.
 *   [adn_slot size="leaderboard" mobile="mobile_banner"]    Different size below 768px.
 *   [adn_slot size="half_page" mobile="none"]               Hidden on phones.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Serving;

final class Shortcode {

	public static function init(): void {
		add_shortcode( 'adn_slot', array( self::class, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function render( $atts ): string {
		if ( ! Frontend::active() ) {
			return '';
		}
		$atts = is_array( $atts ) ? $atts : array();
		$args = array_intersect_key( $atts, array_flip( array( 'placement', 'size', 'mobile' ) ) );
		return Slot::fromArgs( $args );
	}
}
