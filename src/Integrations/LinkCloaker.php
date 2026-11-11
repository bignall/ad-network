<?php
/**
 * Bridge to the Affiliate Link Cloaker plugin, which stores the links ads point to.
 *
 * Ads link to the cloaked URL with a sub-ID of "adn-{ad ID}", so the cloaker's click log also
 * records which ad each click came from. Everything here degrades to "not available" when the
 * cloaker isn't active.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Integrations;

final class LinkCloaker {

	private const LINKS_CLASS = '\\AffLinkCloaker\\Links';

	public static function available(): bool {
		return class_exists( self::LINKS_CLASS ) && post_type_exists( self::postType() );
	}

	public static function postType(): string {
		return class_exists( self::LINKS_CLASS ) ? (string) constant( self::LINKS_CLASS . '::POST_TYPE' ) : 'alc_link';
	}

	/** Cloaked URL for a link, or '' if the link is gone or unpublished. */
	public static function url( int $link_id, string $subid = '' ): string {
		if ( ! self::available() || 'publish' !== get_post_status( $link_id ) ) {
			return '';
		}
		return (string) call_user_func( array( self::LINKS_CLASS, 'cloakedUrl' ), $link_id, $subid );
	}

	/** Whether the affiliate network says the link no longer pays. */
	public static function isDead( int $link_id ): bool {
		return self::available() && (bool) call_user_func( array( self::LINKS_CLASS, 'isDead' ), $link_id );
	}

	/**
	 * Published links, for choosing one in the ad editor.
	 *
	 * @return array<int, string> Link ID => title.
	 */
	public static function choices(): array {
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

	public static function editUrl( int $link_id ): string {
		return (string) get_edit_post_link( $link_id, 'raw' );
	}

	public static function addUrl(): string {
		return admin_url( 'post-new.php?post_type=' . self::postType() );
	}
}
