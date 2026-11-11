<?php
/**
 * Plugin definition: lifecycle handled by WP Foundation.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

use AdNetwork\Tracking\DailyTable;
use AdNetwork\Tracking\EventsTable;
use AdNetwork\Tracking\Rollup;
use AdNetwork\Vendor\Bignall\WPFoundation\Plugin;

final class Bootstrap {

	public const PREFIX = 'adn';

	public static function plugin( string $file ): Plugin {
		return Plugin::create( $file, self::PREFIX )
			->requires( php: '8.1', wp: '6.4', extensions: array( 'json' ) )
			->settings( Settings::store() )
			->tables( new EventsTable(), new DailyTable() )
			->cron( Rollup::HOOK, 'hourly' )
			->postTypes( Ads::POST_TYPE )
			->task( new Tasks\CleanupTask() )
			->deactivationSurvey()
			->deleteDataOnUninstall( static fn (): bool => (bool) Settings::get( 'remove_data' ) )
			->onReady( static fn ( Plugin $plugin ) => App::boot( $plugin ) );
	}
}
