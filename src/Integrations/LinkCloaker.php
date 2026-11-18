<?php
/**
 * Bridge to the Affiliate Link Cloaker plugin, which stores the links ads point to.
 *
 * Ads link to the cloaked URL with a sub-ID of "adn-{ad ID}", so the cloaker's click log also
 * records which ad each click came from. Uses the cloaker's public API (0.4.0+), with a fallback
 * to its internal class for 0.3.x. Everything degrades to "not available" without the cloaker.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Integrations;

final class LinkCloaker {

	private const LEGACY_CLASS = '\\AffLinkCloaker\\Links';

	/** Whether the cloaker's public API (0.4.0+) is loaded. */
	public static function hasApi(): bool {
		return function_exists( 'alc_api_version' );
	}

	public static function available(): bool {
		return ( self::hasApi() || class_exists( self::LEGACY_CLASS ) ) && post_type_exists( self::postType() );
	}

	public static function postType(): string {
		if ( self::hasApi() ) {
			return alc_link_post_type();
		}
		return class_exists( self::LEGACY_CLASS ) ? (string) constant( self::LEGACY_CLASS . '::POST_TYPE' ) : 'alc_link';
	}

	/** Cloaked URL for a link, or '' if the link is gone or unpublished. */
	public static function url( int $link_id, string $subid = '' ): string {
		if ( self::hasApi() ) {
			return alc_cloaked_url( $link_id, $subid );
		}
		if ( ! self::available() || 'publish' !== get_post_status( $link_id ) ) {
			return '';
		}
		return (string) call_user_func( array( self::LEGACY_CLASS, 'cloakedUrl' ), $link_id, $subid );
	}

	/** Whether the affiliate network says the link no longer pays. */
	public static function isDead( int $link_id ): bool {
		if ( self::hasApi() ) {
			return alc_link_is_dead( $link_id );
		}
		return self::available() && (bool) call_user_func( array( self::LEGACY_CLASS, 'isDead' ), $link_id );
	}

	/**
	 * Published links, for choosing one in the ad editor.
	 *
	 * @return array<int, string> Link ID => title.
	 */
	public static function choices(): array {
		if ( self::hasApi() ) {
			return alc_get_links();
		}
		if ( ! self::available() ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => self::postType(),
				'post_status'      => 'publish',
				'numberposts'      => 500,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$out[ $post->ID ] = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * Redirects the cloaker recorded for each ad between two UTC datetimes. These count every
	 * click that reached the cloaked link, including ones our own tracking can't see (no JS,
	 * opened from a copied link). Needs the cloaker's API; [] without it.
	 *
	 * @return array<int, int> Ad ID => clicks.
	 */
	public static function clicksByAd( string $since, string $until ): array {
		if ( ! self::hasApi() ) {
			return array();
		}
		$out = array();
		foreach ( alc_click_counts( array( 'subid_prefix' => 'adn-', 'since' => $since, 'until' => $until, 'group_by' => array( 'subid' ) ) ) as $row ) {
			if ( preg_match( '/^adn-(\d+)$/', (string) ( $row['subid'] ?? '' ), $m ) ) {
				$out[ (int) $m[1] ] = ( $out[ (int) $m[1] ] ?? 0 ) + (int) $row['clicks'];
			}
		}
		return $out;
	}

	public static function editUrl( int $link_id ): string {
		return (string) get_edit_post_link( $link_id, 'raw' );
	}

	public static function addUrl(): string {
		return admin_url( 'post-new.php?post_type=' . self::postType() );
	}
}
