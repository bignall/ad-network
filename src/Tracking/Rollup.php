<?php
/**
 * Rolls raw events up into daily totals, and prunes raw events past the retention period.
 *
 * Runs hourly from cron. Each run recomputes every day from the last run's day onwards (and at
 * least yesterday, for events that land around midnight). Recomputing is idempotent: totals are
 * written with INSERT ... ON DUPLICATE KEY UPDATE, so running twice changes nothing.
 *
 * Raw events are only deleted for days that have been rolled up and won't be recomputed.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tracking;

use AdNetwork\Settings;

final class Rollup {

	public const HOOK = 'adn_rollup';

	/** The first local day the next run recomputes (Y-m-d). */
	public const FROM_OPTION = 'adn_rollup_from';

	private const REFRESH_LOCK = 'adn_rollup_refresh';
	private const PRUNE_BATCH  = 5000;
	private const PRUNE_BATCHES = 40;

	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/** Cron: roll up everything not yet final, then prune. */
	public static function run(): void {
		$today = self::today();
		$from  = (string) get_option( self::FROM_OPTION, '' );
		if ( '' === $from ) {
			$from = self::earliestDay() ?? $today;
		}
		$from = min( $from, self::shiftDay( $today, -1 ) );

		self::rollup( $from );
		update_option( self::FROM_OPTION, $today, false );
		self::prune();
	}

	/**
	 * Bring today's totals up to date for a stats screen, at most every few minutes.
	 */
	public static function refresh(): void {
		if ( get_transient( self::REFRESH_LOCK ) ) {
			return;
		}
		set_transient( self::REFRESH_LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$from = (string) get_option( self::FROM_OPTION, '' );
		if ( '' === $from ) {
			// Never rolled up (e.g. cron isn't running): do the full run now.
			self::run();
			return;
		}
		self::rollup( min( $from, self::today() ) );
	}

	/** Recompute daily totals for every local day from $from_day (Y-m-d) onwards. */
	public static function rollup( string $from_day ): void {
		global $wpdb;
		$events = Events::table();
		$daily  = ( new DailyTable() )->tableName();
		$offset = self::offset();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily} (day, ad_id, placement, serves, views, clicks)
				SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, ad_id, placement,
					SUM(event = %d), SUM(event = %d), SUM(event = %d)
				FROM {$events}
				WHERE created_at >= %s
				GROUP BY d, ad_id, placement
				ON DUPLICATE KEY UPDATE serves = VALUES(serves), views = VALUES(views), clicks = VALUES(clicks)",
				$offset,
				Events::SERVE,
				Events::VIEW,
				Events::CLICK,
				self::dayStartUtc( $from_day )
			)
		);
		// phpcs:enable
	}

	/**
	 * Delete raw events older than the retention period, never touching a day that may still be
	 * recomputed. Batched so one run never locks the table for long.
	 */
	public static function prune(): int {
		global $wpdb;
		$from = (string) get_option( self::FROM_OPTION, '' );
		if ( '' === $from ) {
			return 0;
		}
		$days    = max( Settings::MIN_RETENTION_DAYS, (int) Settings::get( 'retention_days' ) );
		$keep    = self::shiftDay( self::today(), -$days );
		$cutoff  = self::dayStartUtc( min( $keep, $from ) );
		$table   = Events::table();
		$deleted = 0;

		for ( $i = 0; $i < self::PRUNE_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, self::PRUNE_BATCH ) );
			$deleted += $n;
			if ( $n < self::PRUNE_BATCH ) {
				break;
			}
		}
		return $deleted;
	}

	/** Today in the site's timezone. */
	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	/** The site's current UTC offset in seconds. */
	public static function offset(): int {
		return wp_timezone()->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	/** UTC datetime at which a local day starts. */
	public static function dayStartUtc( string $day ): string {
		$start = new \DateTimeImmutable( $day . ' 00:00:00', wp_timezone() );
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** UTC datetime at which a local day ends (inclusive). */
	public static function dayEndUtc( string $day ): string {
		$end = new \DateTimeImmutable( $day . ' 23:59:59', wp_timezone() );
		return $end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function shiftDay( string $day, int $days ): string {
		return ( new \DateTimeImmutable( $day ) )->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/** The local day of the oldest raw event, or null if there are none. */
	private static function earliestDay(): ?string {
		global $wpdb;
		$table = Events::table();
		$first = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $first ) {
			return null;
		}
		return ( new \DateTimeImmutable( (string) $first, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}
}
