<?php
/**
 * Raw ad events: one row per ad served, viewed or clicked.
 *
 * A "serve" row is written when the loader puts an ad in a slot. "view" and "click" rows point
 * back at their serve row through ref_id; the unique key on (ref_id, event) means each served ad
 * counts at most one view and one click, however often the browser reports them.
 *
 * Phase 2 rolls these up into daily totals and prunes old rows.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tracking;

use AdNetwork\Vendor\Bignall\WPFoundation\Database\Table;

final class EventsTable extends Table {

	public function name(): string {
		return 'adn_events';
	}

	public function columns(): string {
		return "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ad_id bigint(20) unsigned NOT NULL,
event tinyint(3) unsigned NOT NULL,
ref_id bigint(20) unsigned DEFAULT NULL,
placement varchar(40) NOT NULL DEFAULT '',
page_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
visitor_hash char(64) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY ref_event (ref_id,event),
KEY ad_event_time (ad_id,event,created_at),
KEY created_at (created_at)";
	}
}
