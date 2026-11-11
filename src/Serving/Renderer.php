<?php
/**
 * Turns an ad into the markup the loader drops into its slot.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Serving;

use AdNetwork\Ads;
use AdNetwork\Integrations\LinkCloaker;
use AdNetwork\Settings;
use AdNetwork\Sizes;

final class Renderer {

	/** Markup for an ad, or '' if it can't be shown right now. */
	public static function render( int $ad_id ): string {
		$size = Sizes::get( Ads::meta( $ad_id, 'size' ) );
		if ( ! $size ) {
			return '';
		}

		// Don't spend a slot on a link the affiliate network has switched off.
		$link_id = (int) Ads::meta( $ad_id, 'link_id' );
		if ( $link_id && LinkCloaker::isDead( $link_id ) ) {
			return '';
		}

		$html = Ads::TYPE_HTML === Ads::type( $ad_id ) ? self::html( $ad_id ) : self::image( $ad_id, $size );

		/**
		 * Filter an ad's markup before it is served.
		 *
		 * @param string $html  Markup.
		 * @param int    $ad_id Ad ID.
		 */
		return (string) apply_filters( 'adn_ad_html', $html, $ad_id );
	}

	/**
	 * @param array{label: string, width: int, height: int, format: string} $size
	 */
	private static function image( int $ad_id, array $size ): string {
		$url = Ads::clickUrl( $ad_id );
		$src = wp_get_attachment_image_url( (int) Ads::meta( $ad_id, 'image_id' ), 'full' );
		if ( '' === $url || ! $src ) {
			return '';
		}
		$alt = Ads::meta( $ad_id, 'alt' );
		if ( '' === $alt ) {
			$alt = get_the_title( $ad_id );
		}
		return sprintf(
			'<a class="adn-image" href="%s"%s rel="%s"><img src="%s" width="%d" height="%d" alt="%s" decoding="async"></a>',
			esc_url( $url ),
			self::target(),
			esc_attr( self::rel() ),
			esc_url( $src ),
			$size['width'],
			$size['height'],
			esc_attr( $alt )
		);
	}

	private static function html( int $ad_id ): string {
		$html = Ads::meta( $ad_id, 'html' );
		if ( '' === trim( $html ) ) {
			return '';
		}

		// {{url}} stands for the ad's link, so one HTML ad can carry the tracked link in several places.
		$html = str_replace( array( '{{url}}', '{{ url }}' ), esc_url( Ads::clickUrl( $ad_id ) ), $html );

		// Third-party ad code is served exactly as pasted; our own HTML may use shortcodes such as
		// [alc_link slug="…"]Buy now[/alc_link].
		if ( ! Ads::isThirdParty( $ad_id ) ) {
			$html = do_shortcode( $html );
		}
		return $html;
	}

	public static function rel(): string {
		return 'sponsored nofollow noopener';
	}

	private static function target(): string {
		return Settings::get( 'new_tab' ) ? ' target="_blank"' : '';
	}
}
