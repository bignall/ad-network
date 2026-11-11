<?php
/**
 * Removes leftovers WP Foundation doesn't track, on uninstall (when the site owner opted in).
 * Ads, the settings option and the events table are handled by WP Foundation itself.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tasks;

use AdNetwork\Ads;
use AdNetwork\Vendor\Bignall\WPFoundation\Plugin;
use AdNetwork\Vendor\Bignall\WPFoundation\Tasks\AbstractTask;

final class CleanupTask extends AbstractTask {

	public function upgrade( Plugin $plugin, string $from, string $to ): void {
		// A changed plugin can change which ads are servable; start from a fresh cache.
		Ads::flushCache();
	}

	public function uninstall( Plugin $plugin ): void {
		Ads::flushCache();
		delete_post_meta_by_key( '_adn_hide_ads' );
	}
}
