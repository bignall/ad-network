<?php
/**
 * The "Ad" post type: one creative (an image or a piece of HTML) of one size.
 *
 * Published ads are in rotation; drafts are paused.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

use AdNetwork\Integrations\LinkCloaker;
use AdNetwork\Tracking\Events;

final class Ads {

	public const POST_TYPE = 'adn_ad';

	public const TYPE_IMAGE = 'image';
	public const TYPE_HTML  = 'html';

	public const DEFAULT_WEIGHT = 10;
	public const MAX_WEIGHT     = 100;

	private const CACHE_KEY = 'adn_servable';

	public const META = array(
		'size'        => '_adn_size',        // Size key, see Sizes.
		'type'        => '_adn_type',        // image | html.
		'image_id'    => '_adn_image_id',    // Attachment ID for image ads.
		'alt'         => '_adn_alt',         // Alt text for image ads.
		'html'        => '_adn_html',        // Markup for HTML ads.
		'link_id'     => '_adn_link_id',     // Affiliate Link Cloaker link ID.
		'url'         => '_adn_url',         // Direct destination, when no cloaked link is chosen.
		'weight'      => '_adn_weight',      // Relative chance of being picked, 1–100.
		'third_party' => '_adn_third_party', // 1 when the HTML carries scripts/iframes (e.g. AdSense).
	);

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'flushCache' ) );
		add_action( 'deleted_post', array( self::class, 'onDeleted' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'onTransition' ), 10, 3 );
		// Ads that use cloaked links stop being servable when the cloaker goes, and come back with it.
		add_action( 'activated_plugin', array( self::class, 'flushCache' ) );
		add_action( 'deactivated_plugin', array( self::class, 'flushCache' ) );
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Ads', 'ad-network' ),
					'singular_name'      => __( 'Ad', 'ad-network' ),
					'add_new'            => __( 'Add Ad', 'ad-network' ),
					'add_new_item'       => __( 'Add Ad', 'ad-network' ),
					'edit_item'          => __( 'Edit Ad', 'ad-network' ),
					'search_items'       => __( 'Search Ads', 'ad-network' ),
					'not_found'          => __( 'No ads found.', 'ad-network' ),
					'not_found_in_trash' => __( 'No ads found in Trash.', 'ad-network' ),
					'all_items'          => __( 'All Ads', 'ad-network' ),
					'menu_name'          => __( 'Ads', 'ad-network' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-megaphone',
				'menu_position'   => 26,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
				'query_var'       => false,
				'capability_type' => 'post',
			)
		);
	}

	public static function meta( int $ad_id, string $key ): string {
		return isset( self::META[ $key ] ) ? (string) get_post_meta( $ad_id, self::META[ $key ], true ) : '';
	}

	public static function setMeta( int $ad_id, string $key, string|int $value ): void {
		if ( isset( self::META[ $key ] ) ) {
			update_post_meta( $ad_id, self::META[ $key ], $value );
		}
	}

	public static function type( int $ad_id ): string {
		return self::TYPE_HTML === self::meta( $ad_id, 'type' ) ? self::TYPE_HTML : self::TYPE_IMAGE;
	}

	public static function weight( int $ad_id ): int {
		$w = self::meta( $ad_id, 'weight' );
		return '' === $w ? self::DEFAULT_WEIGHT : max( 1, min( self::MAX_WEIGHT, (int) $w ) );
	}

	public static function isThirdParty( int $ad_id ): bool {
		return '1' === self::meta( $ad_id, 'third_party' );
	}

	/**
	 * Where a click on the ad goes: the cloaked link (tagged with the ad's sub-ID, so the cloaker's
	 * own click log shows which ad sent it) or the ad's direct URL.
	 */
	public static function clickUrl( int $ad_id ): string {
		$link_id = (int) self::meta( $ad_id, 'link_id' );
		if ( $link_id && LinkCloaker::available() ) {
			$url = LinkCloaker::url( $link_id, self::subId( $ad_id ) );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return self::meta( $ad_id, 'url' );
	}

	/** The sub-ID ads add to cloaked links, e.g. "adn-42". */
	public static function subId( int $ad_id ): string {
		return 'adn-' . $ad_id;
	}

	/** Whether markup contains code that only an admin who allows third-party ad code may run. */
	public static function containsActiveCode( string $html ): bool {
		return (bool) preg_match( '/<\s*(script|iframe|object|embed|form|link|meta|style)\b|<[^>]*\son[a-z]+\s*=|javascript:/i', $html );
	}

	/**
	 * Clean HTML ad markup for saving. Scripts and other active code survive only when the site allows
	 * third-party ad code and the user saving it may post unfiltered HTML.
	 */
	public static function sanitizeHtml( string $html, bool $allow_active ): string {
		if ( $allow_active ) {
			return $html;
		}
		return wp_kses_post( $html );
	}

	/** Whether an ad has everything it needs to be shown. */
	public static function isComplete( int $ad_id ): bool {
		if ( ! Sizes::exists( self::meta( $ad_id, 'size' ) ) ) {
			return false;
		}
		if ( self::TYPE_HTML === self::type( $ad_id ) ) {
			return '' !== trim( self::meta( $ad_id, 'html' ) );
		}
		return (int) self::meta( $ad_id, 'image_id' ) > 0 && '' !== self::clickUrl( $ad_id );
	}

	/**
	 * Ads that can be served right now: published, complete, of an enabled size, and (for
	 * third-party code) allowed by the site. Cached until an ad or the settings change.
	 *
	 * @return list<array{id: int, size: string, weight: int, third_party: bool}>
	 */
	public static function servable(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'fields'                 => 'ids',
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);
		update_meta_cache( 'post', $ids );

		$scripts = Settings::scriptsAllowed();
		$out     = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$size = self::meta( $id, 'size' );
			if ( ! Sizes::isEnabled( $size ) || ! self::isComplete( $id ) ) {
				continue;
			}
			$third_party = self::isThirdParty( $id );
			if ( $third_party && ! $scripts ) {
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'size'        => $size,
				'weight'      => self::weight( $id ),
				'third_party' => $third_party,
			);
		}

		set_transient( self::CACHE_KEY, $out, DAY_IN_SECONDS );
		return $out;
	}

	public static function flushCache(): void {
		delete_transient( self::CACHE_KEY );
	}

	public static function onDeleted( int $post_id, ?\WP_Post $post = null ): void {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			Events::deleteForAd( $post_id );
			self::flushCache();
		}
	}

	public static function onTransition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( self::POST_TYPE === $post->post_type && $new_status !== $old_status ) {
			self::flushCache();
		}
	}
}
