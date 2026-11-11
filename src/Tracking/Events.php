<?php
/**
 * Recording and counting ad events.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tracking;

final class Events {

	public const SERVE = 1;
	public const VIEW  = 2;
	public const CLICK = 3;

	/** @return array<string, int> */
	public static function types(): array {
		return array(
			'serve' => self::SERVE,
			'view'  => self::VIEW,
			'click' => self::CLICK,
		);
	}

	public static function table(): string {
		return ( new EventsTable() )->tableName();
	}

	/** Record that an ad was put in a slot. Returns the event ID, or 0 if it wasn't recorded. */
	public static function recordServe( int $ad_id, string $placement, int $page_id ): int {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'ad_id'        => $ad_id,
				'event'        => self::SERVE,
				'placement'    => substr( $placement, 0, 40 ),
				'page_id'      => $page_id,
				'user_id'      => get_current_user_id(),
				'visitor_hash' => Visitor::hash(),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Record a view or click for a served ad. Copies placement and page from the serve row, and
	 * ignores repeats: each served ad has at most one view and one click.
	 *
	 * A click also records the view if the browser hadn't reported one yet (the ad was clicked
	 * within a second, or the view report was lost): an ad that was clicked was seen, and this
	 * keeps clicks from ever outnumbering views.
	 *
	 * @return bool Whether a new event of the requested kind was recorded.
	 */
	public static function recordFollowUp( int $serve_id, int $ad_id, int $event ): bool {
		if ( ! in_array( $event, array( self::VIEW, self::CLICK ), true ) ) {
			return false;
		}
		if ( self::CLICK === $event ) {
			self::insertFollowUp( $serve_id, $ad_id, self::VIEW );
		}
		return self::insertFollowUp( $serve_id, $ad_id, $event );
	}

	private static function insertFollowUp( int $serve_id, int $ad_id, int $event ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (ad_id, event, ref_id, placement, page_id, user_id, visitor_hash, created_at)
				SELECT ad_id, %d, id, placement, page_id, %d, %s, %s FROM {$table} WHERE id = %d AND ad_id = %d AND event = %d",
				$event,
				get_current_user_id(),
				Visitor::hash(),
				current_time( 'mysql', true ),
				$serve_id,
				$ad_id,
				self::SERVE
			)
		);
		// phpcs:enable
		$recorded = is_int( $rows ) && $rows > 0;

		if ( $recorded ) {
			/**
			 * Fires after a new view or click is recorded. Phase 4 awards credits here.
			 *
			 * @param int $ad_id    Ad ID.
			 * @param int $event    Events::VIEW or Events::CLICK.
			 * @param int $serve_id The serve event it follows.
			 */
			do_action( 'adn_event_recorded', $ad_id, $event, $serve_id );
		}
		return $recorded;
	}

	public static function deleteForAd( int $ad_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'ad_id' => $ad_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( ( new DailyTable() )->tableName(), array( 'ad_id' => $ad_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
