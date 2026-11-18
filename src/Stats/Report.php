<?php
/**
 * Stats queries over the daily totals.
 *
 *     $report = new Report( '2026-11-01', '2026-11-30', [ 'ad' => 42 ] );
 *     $report->totals();   // ['serves' => …, 'views' => …, 'clicks' => …]
 *
 * Dates are local days (site timezone), inclusive.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Stats;

use AdNetwork\Integrations\LinkCloaker;
use AdNetwork\Tracking\DailyTable;
use AdNetwork\Tracking\Events;
use AdNetwork\Tracking\Rollup;

final class Report {

	/** @var array{ad: int, placement: string} */
	private array $filters;

	/**
	 * @param string               $from    First day, Y-m-d.
	 * @param string               $to      Last day, Y-m-d.
	 * @param array<string, mixed> $filters ad (ID), placement (key).
	 */
	public function __construct( private string $from, private string $to, array $filters = array() ) {
		if ( $this->from > $this->to ) {
			[ $this->from, $this->to ] = array( $this->to, $this->from );
		}
		$this->filters = array(
			'ad'        => (int) ( $filters['ad'] ?? 0 ),
			'placement' => (string) ( $filters['placement'] ?? '' ),
		);
	}

	/** The last N days, ending today. */
	public static function lastDays( int $days, array $filters = array() ): self {
		$today = Rollup::today();
		return new self( Rollup::shiftDay( $today, -( $days - 1 ) ), $today, $filters );
	}

	public function from(): string {
		return $this->from;
	}

	public function to(): string {
		return $this->to;
	}

	/** @return array{ad: int, placement: string} */
	public function filters(): array {
		return $this->filters;
	}

	/** Number of days in the range. */
	public function days(): int {
		return (int) ( new \DateTimeImmutable( $this->from ) )->diff( new \DateTimeImmutable( $this->to ) )->days + 1;
	}

	/** @return array{serves: int, views: int, clicks: int} */
	public function totals(): array {
		$rows = $this->query( 'SUM(serves) AS serves, SUM(views) AS views, SUM(clicks) AS clicks', '' );
		return self::counts( $rows[0] ?? array() );
	}

	/**
	 * One row per day in the range, including days with nothing.
	 *
	 * @return list<array{day: string, serves: int, views: int, clicks: int}>
	 */
	public function series(): array {
		$rows   = $this->query( 'day, SUM(serves) AS serves, SUM(views) AS views, SUM(clicks) AS clicks', 'GROUP BY day' );
		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ (string) $row['day'] ] = self::counts( $row );
		}
		$out = array();
		for ( $day = $this->from; $day <= $this->to; $day = Rollup::shiftDay( $day, 1 ) ) {
			$out[] = array( 'day' => $day ) + ( $by_day[ $day ] ?? array( 'serves' => 0, 'views' => 0, 'clicks' => 0 ) );
		}
		return $out;
	}

	/**
	 * Totals per ad, most viewed first.
	 *
	 * @return array<int, array{serves: int, views: int, clicks: int}> Ad ID => totals.
	 */
	public function byAd(): array {
		$out = array();
		foreach ( $this->query( 'ad_id, SUM(serves) AS serves, SUM(views) AS views, SUM(clicks) AS clicks', 'GROUP BY ad_id ORDER BY views DESC, serves DESC' ) as $row ) {
			$out[ (int) $row['ad_id'] ] = self::counts( $row );
		}
		return $out;
	}

	/**
	 * Totals per placement, most viewed first.
	 *
	 * @return array<string, array{serves: int, views: int, clicks: int}> Placement => totals.
	 */
	public function byPlacement(): array {
		$out = array();
		foreach ( $this->query( 'placement, SUM(serves) AS serves, SUM(views) AS views, SUM(clicks) AS clicks', 'GROUP BY placement ORDER BY views DESC, serves DESC' ) as $row ) {
			$out[ (string) $row['placement'] ] = self::counts( $row );
		}
		return $out;
	}

	/**
	 * Every daily row in the range, for export.
	 *
	 * @return list<array{day: string, ad_id: int, placement: string, serves: int, views: int, clicks: int}>
	 */
	public function rows(): array {
		return array_map(
			static fn ( array $r ): array => array(
				'day'       => (string) $r['day'],
				'ad_id'     => (int) $r['ad_id'],
				'placement' => (string) $r['placement'],
			) + self::counts( $r ),
			$this->query( 'day, ad_id, placement, serves, views, clicks', 'ORDER BY day, ad_id, placement' )
		);
	}

	/**
	 * Totals for specific ads (zeros for ads with nothing), for list columns.
	 *
	 * @param list<int> $ad_ids
	 * @return array<int, array{serves: int, views: int, clicks: int}>
	 */
	public function forAds( array $ad_ids ): array {
		$out = array();
		foreach ( $ad_ids as $id ) {
			$out[ (int) $id ] = array( 'serves' => 0, 'views' => 0, 'clicks' => 0 );
		}
		if ( ! $out ) {
			return $out;
		}
		$in = implode( ',', array_map( 'intval', array_keys( $out ) ) );
		foreach ( $this->query( 'ad_id, SUM(serves) AS serves, SUM(views) AS views, SUM(clicks) AS clicks', 'GROUP BY ad_id', "ad_id IN ({$in})" ) as $row ) {
			$out[ (int) $row['ad_id'] ] = self::counts( $row );
		}
		return $out;
	}

	/**
	 * Pages that showed the most ads. Comes from the raw events, so only covers the retention
	 * period (Settings → Keep detailed events).
	 *
	 * @return list<array{page_id: int, serves: int, views: int, clicks: int}>
	 */
	public function topPages( int $limit = 10 ): array {
		global $wpdb;
		$table  = Events::table();
		$where  = array( 'created_at BETWEEN %s AND %s', 'page_id > 0' );
		$params = array( Rollup::dayStartUtc( $this->from ), Rollup::dayEndUtc( $this->to ) );
		if ( $this->filters['ad'] ) {
			$where[]  = 'ad_id = %d';
			$params[] = $this->filters['ad'];
		}
		if ( '' !== $this->filters['placement'] ) {
			$where[]  = 'placement = %s';
			$params[] = $this->filters['placement'];
		}
		$params[] = $limit;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_id, SUM(event = %d) AS serves, SUM(event = %d) AS views, SUM(event = %d) AS clicks
				FROM {$table} WHERE " . implode( ' AND ', $where ) . '
				GROUP BY page_id ORDER BY views DESC, serves DESC LIMIT %d',
				array_merge( array( Events::SERVE, Events::VIEW, Events::CLICK ), $params )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map( static fn ( array $r ): array => array( 'page_id' => (int) $r['page_id'] ) + self::counts( $r ), $rows );
	}

	/**
	 * Redirects through the cloaked links, per ad, from the cloaker's own click log.
	 *
	 * @return array<int, int> Ad ID => clicks.
	 */
	public function linkClicksByAd(): array {
		return LinkCloaker::clicksByAd( Rollup::dayStartUtc( $this->from ), Rollup::dayEndUtc( $this->to ) );
	}

	/** Views per serve, as a percentage string, or '—'. */
	public static function viewRate( array $t ): string {
		return self::percent( (int) ( $t['views'] ?? 0 ), (int) ( $t['serves'] ?? 0 ) );
	}

	/** Clicks per view, as a percentage string, or '—'. */
	public static function ctr( array $t ): string {
		return self::percent( (int) ( $t['clicks'] ?? 0 ), (int) ( $t['views'] ?? 0 ) );
	}

	public static function percent( int $part, int $whole ): string {
		if ( $whole <= 0 ) {
			return '—';
		}
		return number_format_i18n( 100 * $part / $whole, 2 ) . '%';
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{serves: int, views: int, clicks: int}
	 */
	private static function counts( array $row ): array {
		return array(
			'serves' => (int) ( $row['serves'] ?? 0 ),
			'views'  => (int) ( $row['views'] ?? 0 ),
			'clicks' => (int) ( $row['clicks'] ?? 0 ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function query( string $select, string $tail, string $extra_where = '' ): array {
		global $wpdb;
		$table  = ( new DailyTable() )->tableName();
		$where  = array( 'day BETWEEN %s AND %s' );
		$params = array( $this->from, $this->to );
		if ( $this->filters['ad'] ) {
			$where[]  = 'ad_id = %d';
			$params[] = $this->filters['ad'];
		}
		if ( '' !== $this->filters['placement'] ) {
			$where[]  = 'placement = %s';
			$params[] = $this->filters['placement'];
		}
		if ( '' !== $extra_where ) {
			$where[] = $extra_where;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT {$select} FROM {$table} WHERE " . implode( ' AND ', $where ) . " {$tail}", $params ),
			ARRAY_A
		);
		// phpcs:enable
	}
}
