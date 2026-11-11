<?php
/**
 * Daily totals per ad and placement, rolled up from the raw events table. Days are in the site's
 * timezone. Stats read from here, so raw events can be pruned without losing history.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tracking;

use AdNetwork\Vendor\Bignall\WPFoundation\Database\Table;

final class DailyTable extends Table {

	public function name(): string {
		return 'adn_daily';
	}

	public function columns(): string {
		return "day date NOT NULL,
ad_id bigint(20) unsigned NOT NULL,
placement varchar(40) NOT NULL DEFAULT '',
serves int(10) unsigned NOT NULL DEFAULT 0,
views int(10) unsigned NOT NULL DEFAULT 0,
clicks int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (day,ad_id,placement),
KEY ad_day (ad_id,day)";
	}
}
