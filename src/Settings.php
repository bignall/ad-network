<?php
/**
 * Plugin settings, stored in one option through WP Foundation's Settings.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

use AdNetwork\Vendor\Bignall\WPFoundation\Settings\Settings as Store;

final class Settings {

	public const OPTION = 'adn_settings';
	public const GROUP  = 'adn-settings';

	/** Raw events are kept at least this long; older days live on in the daily totals. */
	public const MIN_RETENTION_DAYS = 7;

	private static ?Store $store = null;

	/** The settings object, shared with WP Foundation (which adds, registers and removes it). */
	public static function store(): Store {
		if ( null === self::$store ) {
			self::$store = new Store( self::OPTION, self::defaults(), array( self::class, 'sanitize' ), self::GROUP );
		}
		return self::$store;
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'sizes'          => Sizes::defaultEnabled(),
			'placements'     => array(),
			'allow_scripts'  => 0,
			'label'          => 'Advertisement',
			'new_tab'        => 1,
			'content_types'  => array( 'post' ),
			'track'          => 1,
			'ignore_bots'    => 1,
			'track_admins'   => 0,
			'retention_days' => 90,
			'remove_data'    => 0,
		);
	}

	/** @return array<string, mixed> */
	public static function all(): array {
		return self::store()->all();
	}

	public static function get( string $key ): mixed {
		return self::store()->get( $key );
	}

	public static function flush(): void {
		self::store()->flush();
	}

	/**
	 * Whether HTML ads may contain scripts, iframes and other third-party ad code. Site-wide switch;
	 * saving such code also needs the unfiltered_html capability.
	 */
	public static function scriptsAllowed(): bool {
		return (bool) self::get( 'allow_scripts' );
	}

	public static function init(): void {
		// Registration and cache flushing are WP Foundation's; which ads are servable is ours.
		add_action( 'update_option_' . self::OPTION, array( Ads::class, 'flushCache' ) );
	}

	/**
	 * Clean the submitted settings form.
	 *
	 * @param array<string, mixed> $input   Submitted settings.
	 * @param Store                $current Current settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, Store $current ): array {
		$out = self::defaults();

		$out['sizes'] = array_values( array_intersect( array_map( 'strval', (array) ( $input['sizes'] ?? array() ) ), array_keys( Sizes::all() ) ) );

		$enabled = array_flip( $out['sizes'] );
		foreach ( Placements::all() as $key => $placement ) {
			$in     = is_array( $input['placements'][ $key ] ?? null ) ? $input['placements'][ $key ] : array();
			$config = array( 'enabled' => empty( $in['enabled'] ) ? 0 : 1 );
			foreach ( array( 'desktop', 'mobile' ) as $device ) {
				$size              = sanitize_key( (string) ( $in[ $device ] ?? '' ) );
				$config[ $device ] = isset( $enabled[ $size ] ) ? $size : ( 'mobile' === $device ? '' : (string) $placement['defaults']['desktop'] );
			}
			if ( array_key_exists( 'paragraph', $placement['defaults'] ) ) {
				$config['paragraph'] = max( 1, min( 50, (int) ( $in['paragraph'] ?? $placement['defaults']['paragraph'] ) ) );
			}
			$out['placements'][ $key ] = $config;
		}

		$out['content_types'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['content_types'] ?? array() ) ), 'post_type_exists' ) );
		$out['label']         = sanitize_text_field( (string) ( $input['label'] ?? '' ) );

		$out['retention_days'] = max( self::MIN_RETENTION_DAYS, min( 3650, (int) ( $input['retention_days'] ?? self::defaults()['retention_days'] ) ) );

		foreach ( array( 'new_tab', 'track', 'ignore_bots', 'track_admins', 'remove_data' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		// Only someone who could paste scripts themselves may let ads carry them.
		$out['allow_scripts'] = current_user_can( 'unfiltered_html' )
			? ( empty( $input['allow_scripts'] ) ? 0 : 1 )
			: (int) $current->get( 'allow_scripts' );

		return $out;
	}
}
