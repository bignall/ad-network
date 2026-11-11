<?php
/**
 * Front end: prints the automatic placements and loads the ad loader.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Serving;

use AdNetwork\App;
use AdNetwork\Placements;
use AdNetwork\Rest;
use AdNetwork\Settings;

final class Frontend {

	public const HANDLE = 'adn-frontend';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register' ) );
		add_action( 'wp_body_open', array( self::class, 'header' ), 5 );
		add_action( 'wp_footer', array( self::class, 'footer' ), 5 );
		// After every slot is printed (footer is at 5), before footer scripts print (at 20).
		add_action( 'wp_footer', array( self::class, 'maybeEnqueue' ), 15 );
		add_filter( 'the_content', array( self::class, 'inContent' ), 20 );
	}

	/** Whether ads should appear on the current request at all. */
	public static function active(): bool {
		$active = ! is_admin() && ! is_feed() && ! is_robots() && ! is_trackback() && ! is_404() && ! is_customize_preview();
		/**
		 * Filter whether this page shows ads.
		 *
		 * @param bool $active
		 */
		return (bool) apply_filters( 'adn_show_ads', $active );
	}

	public static function register(): void {
		$version = App::version();
		wp_register_style( self::HANDLE, App::url( 'assets/frontend.css' ), array(), $version );
		wp_register_script( self::HANDLE, App::url( 'assets/frontend.js' ), array(), $version, array( 'strategy' => 'defer', 'in_footer' => true ) );

		$config = array(
			'endpoint'   => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
			'breakpoint' => Placements::MOBILE_BREAKPOINT,
			'pageId'     => is_singular() ? get_queried_object_id() : 0,
		);
		// Logged-in pages are rarely cached, so the nonce is fresh; it lets the REST API know who's
		// looking (so admins can be left out of the stats). Cached pages never include one.
		if ( is_user_logged_in() ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		wp_add_inline_script( self::HANDLE, 'window.adnConfig=' . wp_json_encode( $config ) . ';', 'before' );

		// Styles are tiny and slots reserve their space with them, so load them up front.
		if ( self::active() ) {
			wp_enqueue_style( self::HANDLE );
		}
	}

	/** Load the script only on pages that printed a slot. */
	public static function maybeEnqueue(): void {
		if ( Slot::used() ) {
			wp_enqueue_script( self::HANDLE );
			wp_enqueue_style( self::HANDLE );
		}
	}

	public static function header(): void {
		if ( self::active() ) {
			echo Slot::forPlacement( 'header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function footer(): void {
		if ( self::active() ) {
			echo Slot::forPlacement( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Insert the in-content slot after paragraph N of the main post, if the post is long enough.
	 */
	public static function inContent( string $content ): string {
		if ( ! self::active() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! in_array( get_post_type(), (array) Settings::get( 'content_types' ), true ) ) {
			return $content;
		}
		if ( get_post_meta( (int) get_the_ID(), '_adn_hide_ads', true ) ) {
			return $content;
		}
		$config = Placements::config( 'in_content' );
		if ( ! $config || empty( $config['enabled'] ) ) {
			return $content;
		}
		return self::insertAfterParagraph( $content, Slot::forPlacement( 'in_content' ), (int) $config['paragraph'] );
	}

	/**
	 * Put markup after the Nth closing </p>. Content with fewer paragraphs is left alone: an ad at
	 * the very end of a short post looks like part of the footer.
	 */
	public static function insertAfterParagraph( string $content, string $insert, int $n ): string {
		if ( '' === $insert || $n < 1 ) {
			return $content;
		}
		$parts = preg_split( '#(</p>)#i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $content;
		}
		$closings = intdiv( count( $parts ) - 1, 2 );
		if ( $closings <= $n ) {
			return $content;
		}
		$out   = '';
		$count = 0;
		foreach ( $parts as $part ) {
			$out .= $part;
			if ( 0 === strcasecmp( $part, '</p>' ) && ++$count === $n ) {
				$out .= $insert;
			}
		}
		return $out;
	}
}
