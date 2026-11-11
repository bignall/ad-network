<?php
/**
 * Placements: the places on the site an ad can appear, and their settings.
 *
 * Each placement has a desktop size and a mobile size (used below 768px; "" hides the slot on
 * mobile). The site admin turns placements on or off in Ads → Settings.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

final class Placements {

	public const MOBILE_BREAKPOINT = 768;

	/**
	 * @return array<string, array{label: string, description: string, formats: list<string>, defaults: array<string, mixed>}>
	 */
	public static function all(): array {
		$placements = array(
			'header'     => array(
				'label'       => __( 'Header banner', 'ad-network' ),
				'description' => __( 'At the top of every page, right after the opening <body> tag.', 'ad-network' ),
				'formats'     => array( Sizes::FORMAT_BANNER ),
				'defaults'    => array( 'enabled' => 0, 'desktop' => 'leaderboard', 'mobile' => 'mobile_banner' ),
			),
			'footer'     => array(
				'label'       => __( 'Footer banner', 'ad-network' ),
				'description' => __( 'At the bottom of every page.', 'ad-network' ),
				'formats'     => array( Sizes::FORMAT_BANNER ),
				'defaults'    => array( 'enabled' => 0, 'desktop' => 'leaderboard', 'mobile' => 'mobile_banner' ),
			),
			'in_content' => array(
				'label'       => __( 'In content', 'ad-network' ),
				'description' => __( 'Inside posts, after the chosen paragraph.', 'ad-network' ),
				'formats'     => array( Sizes::FORMAT_RECTANGLE, Sizes::FORMAT_BANNER ),
				'defaults'    => array( 'enabled' => 0, 'desktop' => 'medium_rectangle', 'mobile' => 'medium_rectangle', 'paragraph' => 3 ),
			),
			'sidebar'    => array(
				'label'       => __( 'Sidebar', 'ad-network' ),
				'description' => __( 'Wherever you put the Ad Slot widget, [adn_slot placement="sidebar"] or adn_slot( \'sidebar\' ).', 'ad-network' ),
				'formats'     => array( Sizes::FORMAT_RECTANGLE, Sizes::FORMAT_VERTICAL ),
				'defaults'    => array( 'enabled' => 0, 'desktop' => 'medium_rectangle', 'mobile' => 'medium_rectangle' ),
			),
		);

		/**
		 * Add placements. A custom placement is shown with [adn_slot placement="key"].
		 *
		 * @param array<string, array{label: string, description: string, formats: list<string>, defaults: array<string, mixed>}> $placements
		 */
		return (array) apply_filters( 'adn_placements', $placements );
	}

	/** @return array<string, array<string, mixed>> Default settings for every placement. */
	public static function defaults(): array {
		return array_map( static fn ( array $p ): array => $p['defaults'], self::all() );
	}

	/**
	 * A placement's saved settings, merged over its defaults, or null if it doesn't exist.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function config( string $key ): ?array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		$saved = (array) Settings::get( 'placements' );
		return array_merge( $all[ $key ]['defaults'], is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : array() );
	}

	public static function isEnabled( string $key ): bool {
		$config = self::config( $key );
		return null !== $config && ! empty( $config['enabled'] );
	}

	/**
	 * Sizes that make sense for a placement, among those the site has turned on.
	 *
	 * @return array<string, array{label: string, width: int, height: int, format: string}>
	 */
	public static function sizeChoices( string $key ): array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return array();
		}
		$formats = $all[ $key ]['formats'];
		return array_filter( Sizes::enabled(), static fn ( array $s ): bool => in_array( $s['format'], $formats, true ) );
	}
}
