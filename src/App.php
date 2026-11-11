<?php
/**
 * Wires the plugin's runtime services once WP Foundation says it's ready.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

use AdNetwork\Vendor\Bignall\WPFoundation\Plugin;

final class App {

	private static ?self $instance = null;

	private function __construct( public readonly Plugin $plugin ) {}

	public static function boot( Plugin $plugin ): void {
		self::$instance = new self( $plugin );

		load_plugin_textdomain( 'ad-network', false, dirname( $plugin->basename() ) . '/languages' );

		Ads::init();
		Settings::init();
		Rest::init();
		Tracking\Rollup::init();
		Serving\Frontend::init();
		Serving\Shortcode::init();
		Serving\Widget::init();

		if ( is_admin() ) {
			Admin\AdEditor::init();
			Admin\AdList::init();
			Admin\SettingsPage::init( $plugin );
			Admin\StatsPage::init();
		}
	}

	public static function ready(): bool {
		return null !== self::$instance;
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			throw new \LogicException( 'Ad Network is not ready yet.' );
		}
		return self::$instance;
	}

	public static function url( string $path = '' ): string {
		return plugins_url( $path, ADN_FILE );
	}

	public static function version(): string {
		return self::instance()->plugin->currentVersion();
	}
}
