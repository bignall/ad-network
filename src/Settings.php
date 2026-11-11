<?php
/**
 * Plugin settings, stored in one option.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

final class Settings {

	public const OPTION = 'adn_settings';
	public const GROUP  = 'adn-settings';

	/** @var array<string, mixed>|null Request cache. */
	private static ?array $cache = null;

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'sizes'         => Sizes::defaultEnabled(),
			'placements'    => array(),
			'allow_scripts' => 0,
			'label'         => 'Advertisement',
			'new_tab'       => 1,
			'content_types' => array( 'post' ),
			'track'         => 1,
			'ignore_bots'   => 1,
			'track_admins'  => 0,
			'remove_data'   => 0,
		);
	}

	/** @return array<string, mixed> */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Whether HTML ads may contain scripts, iframes and other third-party ad code. Site-wide switch;
	 * saving such code also needs the unfiltered_html capability.
	 */
	public static function scriptsAllowed(): bool {
		return (bool) self::get( 'allow_scripts' );
	}

	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'flush' ) );
		add_action( 'update_option_' . self::OPTION, array( Ads::class, 'flushCache' ) );
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

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

		foreach ( array( 'new_tab', 'track', 'ignore_bots', 'track_admins', 'remove_data' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		// Only someone who could paste scripts themselves may let ads carry them.
		$out['allow_scripts'] = current_user_can( 'unfiltered_html' )
			? ( empty( $input['allow_scripts'] ) ? 0 : 1 )
			: (int) self::get( 'allow_scripts' );

		return $out;
	}
}
