<?php
/**
 * Ads → Stats: totals, a daily chart, and breakdowns by ad, placement and page, for a date range
 * and optionally one ad or placement. Exports the same slice as CSV.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Admin;

use AdNetwork\Ads;
use AdNetwork\App;
use AdNetwork\Integrations\LinkCloaker;
use AdNetwork\Placements;
use AdNetwork\Settings;
use AdNetwork\Sizes;
use AdNetwork\Stats\Report;
use AdNetwork\Tracking\Rollup;

final class StatsPage {

	public const SLUG       = 'adn-stats';
	public const CAPABILITY = 'edit_others_posts';

	private const PRESETS = array( '7', '30', '90', 'month', 'custom' );

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_adn_export', array( self::class, 'export' ) );
	}

	/**
	 * @param array<string, scalar> $args Query args (range, from, to, ad, placement).
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'post_type' => Ads::POST_TYPE, 'page' => self::SLUG ), $args ),
			admin_url( 'edit.php' )
		);
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Ads::POST_TYPE, __( 'Ad Stats', 'ad-network' ), __( 'Stats', 'ad-network' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ), 1 );
	}

	public static function assets( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'adn-admin', App::url( 'assets/admin.css' ), array(), App::version() );
		wp_enqueue_script( 'adn-stats', App::url( 'assets/stats.js' ), array(), App::version(), true );
	}

	/**
	 * The report for the current request's filters.
	 *
	 * @param array<string, mixed> $q Query args.
	 * @return array{0: Report, 1: string} Report and the chosen preset.
	 */
	public static function reportFromRequest( array $q ): array {
		$range   = in_array( (string) ( $q['range'] ?? '' ), self::PRESETS, true ) ? (string) $q['range'] : '30';
		$filters = array(
			'ad'        => absint( $q['ad'] ?? 0 ),
			'placement' => sanitize_key( (string) ( $q['placement'] ?? '' ) ),
		);
		$today = Rollup::today();

		if ( 'custom' === $range ) {
			$from = self::validDay( (string) ( $q['from'] ?? '' ) ) ?? Rollup::shiftDay( $today, -29 );
			$to   = self::validDay( (string) ( $q['to'] ?? '' ) ) ?? $today;
			return array( new Report( $from, min( $to, $today ), $filters ), $range );
		}
		if ( 'month' === $range ) {
			return array( new Report( substr( $today, 0, 8 ) . '01', $today, $filters ), $range );
		}
		return array( Report::lastDays( (int) $range, $filters ), $range );
	}

	private static function validDay( string $day ): ?string {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day );
		return $d && $d->format( 'Y-m-d' ) === $day ? $day : null;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		Rollup::refresh();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		[ $report, $range ] = self::reportFromRequest( wp_unslash( $_GET ) );
		$filters            = $report->filters();
		$totals             = $report->totals();
		$by_ad              = $report->byAd();
		$link_clicks        = $report->linkClicksByAd();
		$has_links          = LinkCloaker::hasApi();
		$ads                = get_posts(
			array(
				'post_type'        => Ads::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$titles = array();
		foreach ( $ads as $ad ) {
			$titles[ $ad->ID ] = '' !== $ad->post_title ? $ad->post_title : sprintf( '#%d', $ad->ID );
		}
		$placements = Placements::all();
		$total_link = $filters['ad'] ? ( $link_clicks[ $filters['ad'] ] ?? 0 ) : array_sum( $link_clicks );
		$base       = array_filter(
			array(
				'range'     => $range,
				'from'      => 'custom' === $range ? $report->from() : '',
				'to'        => 'custom' === $range ? $report->to() : '',
				'ad'        => $filters['ad'] ?: '',
				'placement' => $filters['placement'],
			)
		);
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'adn_export' ), $base ), admin_url( 'admin-post.php' ) ), 'adn_export' );

		$series = $report->series();
		$chart  = array(
			'days'   => array_column( $series, 'day' ),
			'serves' => array_column( $series, 'serves' ),
			'views'  => array_column( $series, 'views' ),
			'clicks' => array_column( $series, 'clicks' ),
			'labels' => array(
				'serves' => __( 'Served', 'ad-network' ),
				'views'  => __( 'Viewed', 'ad-network' ),
				'clicks' => __( 'Clicks', 'ad-network' ),
			),
		);
		?>
		<div class="wrap adn-stats-page">
			<h1><?php esc_html_e( 'Ad Stats', 'ad-network' ); ?></h1>

			<form method="get" class="adn-filters">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Ads::POST_TYPE ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="adn-range"><?php esc_html_e( 'Date range', 'ad-network' ); ?></label>
				<select id="adn-range" name="range">
					<option value="7" <?php selected( $range, '7' ); ?>><?php esc_html_e( 'Last 7 days', 'ad-network' ); ?></option>
					<option value="30" <?php selected( $range, '30' ); ?>><?php esc_html_e( 'Last 30 days', 'ad-network' ); ?></option>
					<option value="90" <?php selected( $range, '90' ); ?>><?php esc_html_e( 'Last 90 days', 'ad-network' ); ?></option>
					<option value="month" <?php selected( $range, 'month' ); ?>><?php esc_html_e( 'This month', 'ad-network' ); ?></option>
					<option value="custom" <?php selected( $range, 'custom' ); ?>><?php esc_html_e( 'Custom…', 'ad-network' ); ?></option>
				</select>
				<span class="adn-custom-range" <?php echo 'custom' === $range ? '' : 'hidden'; ?>>
					<label><span class="screen-reader-text"><?php esc_html_e( 'From', 'ad-network' ); ?></span><input type="date" name="from" value="<?php echo esc_attr( $report->from() ); ?>"></label>
					&ndash;
					<label><span class="screen-reader-text"><?php esc_html_e( 'To', 'ad-network' ); ?></span><input type="date" name="to" value="<?php echo esc_attr( $report->to() ); ?>" max="<?php echo esc_attr( Rollup::today() ); ?>"></label>
				</span>
				<label class="screen-reader-text" for="adn-filter-ad"><?php esc_html_e( 'Ad', 'ad-network' ); ?></label>
				<select id="adn-filter-ad" name="ad">
					<option value=""><?php esc_html_e( 'All ads', 'ad-network' ); ?></option>
					<?php foreach ( $titles as $id => $title ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filters['ad'], $id ); ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="adn-filter-placement"><?php esc_html_e( 'Placement', 'ad-network' ); ?></label>
				<select id="adn-filter-placement" name="placement">
					<option value=""><?php esc_html_e( 'All placements', 'ad-network' ); ?></option>
					<?php foreach ( self::placementLabels( $placements ) as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['placement'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Apply', 'ad-network' ), 'secondary', '', false ); ?>
				<a class="button adn-export" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'ad-network' ); ?></a>
			</form>

			<p class="adn-range-note">
				<?php
				printf(
					/* translators: 1: first day, 2: last day */
					esc_html__( '%1$s to %2$s (site time)', 'ad-network' ),
					esc_html( wp_date( get_option( 'date_format' ), strtotime( $report->from() . ' 12:00:00' ) ) ),
					esc_html( wp_date( get_option( 'date_format' ), strtotime( $report->to() . ' 12:00:00' ) ) )
				);
				?>
			</p>

			<div class="adn-tiles">
				<?php
				self::tile( __( 'Served', 'ad-network' ), number_format_i18n( $totals['serves'] ), __( 'Ads put in a slot', 'ad-network' ) );
				/* translators: %s: percentage */
				self::tile( __( 'Viewed', 'ad-network' ), number_format_i18n( $totals['views'] ), sprintf( __( '%s of served', 'ad-network' ), Report::viewRate( $totals ) ) );
				self::tile( __( 'Clicks', 'ad-network' ), number_format_i18n( $totals['clicks'] ), __( 'Seen on the page', 'ad-network' ) );
				self::tile( __( 'CTR', 'ad-network' ), Report::ctr( $totals ), __( 'Clicks per view', 'ad-network' ) );
				if ( $has_links ) {
					self::tile( __( 'Link clicks', 'ad-network' ), number_format_i18n( $total_link ), __( 'Recorded by Link Cloaker', 'ad-network' ) );
				}
				?>
			</div>

			<div class="adn-card">
				<h2><?php esc_html_e( 'Served and viewed per day', 'ad-network' ); ?></h2>
				<div class="adn-chart" data-adn-chart="line" data-series="serves,views" aria-hidden="true"></div>
				<h2><?php esc_html_e( 'Clicks per day', 'ad-network' ); ?></h2>
				<div class="adn-chart adn-chart--short" data-adn-chart="bar" data-series="clicks" aria-hidden="true"></div>
				<details class="adn-table-view">
					<summary><?php esc_html_e( 'Show as a table', 'ad-network' ); ?></summary>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Day', 'ad-network' ); ?></th><th class="num"><?php esc_html_e( 'Served', 'ad-network' ); ?></th><th class="num"><?php esc_html_e( 'Viewed', 'ad-network' ); ?></th><th class="num"><?php esc_html_e( 'Clicks', 'ad-network' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $series as $row ) : ?>
								<tr><td><?php echo esc_html( $row['day'] ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['serves'] ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<script type="application/json" id="adn-chart-data"><?php echo wp_json_encode( $chart, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
			</div>

			<?php if ( ! $filters['ad'] ) : ?>
				<div class="adn-card">
					<h2><?php esc_html_e( 'By ad', 'ad-network' ); ?></h2>
					<?php if ( ! $by_ad ) : ?>
						<p><?php esc_html_e( 'No ads were served in this period.', 'ad-network' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Ad', 'ad-network' ); ?></th>
									<th><?php esc_html_e( 'Size', 'ad-network' ); ?></th>
									<?php self::countHeaders( $has_links ); ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $by_ad as $ad_id => $t ) : ?>
									<tr>
										<td>
											<?php if ( isset( $titles[ $ad_id ] ) ) : ?>
												<a href="<?php echo esc_url( self::url( array_merge( $base, array( 'ad' => $ad_id ) ) ) ); ?>"><?php echo esc_html( $titles[ $ad_id ] ); ?></a>
											<?php else : ?>
												<?php
												/* translators: %d: ad ID */
												echo esc_html( sprintf( __( 'Deleted ad #%d', 'ad-network' ), $ad_id ) );
												?>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( Sizes::label( Ads::meta( $ad_id, 'size' ) ) ); ?></td>
										<?php self::countCells( $t, $has_links ? ( $link_clicks[ $ad_id ] ?? 0 ) : null ); ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="adn-columns">
				<div class="adn-card">
					<h2><?php esc_html_e( 'By placement', 'ad-network' ); ?></h2>
					<?php $by_placement = $report->byPlacement(); ?>
					<?php if ( ! $by_placement ) : ?>
						<p><?php esc_html_e( 'Nothing in this period.', 'ad-network' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead><tr><th><?php esc_html_e( 'Placement', 'ad-network' ); ?></th><?php self::countHeaders( false ); ?></tr></thead>
							<tbody>
								<?php foreach ( $by_placement as $key => $t ) : ?>
									<tr><td><?php echo esc_html( self::placementLabels( $placements )[ $key ] ?? ( '' === $key ? __( 'Other', 'ad-network' ) : $key ) ); ?></td><?php self::countCells( $t, null ); ?></tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="adn-card">
					<h2><?php esc_html_e( 'Top pages', 'ad-network' ); ?></h2>
					<?php $pages = $report->topPages(); ?>
					<?php if ( ! $pages ) : ?>
						<p><?php esc_html_e( 'Nothing in this period.', 'ad-network' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead><tr><th><?php esc_html_e( 'Page', 'ad-network' ); ?></th><?php self::countHeaders( false ); ?></tr></thead>
							<tbody>
								<?php foreach ( $pages as $row ) : ?>
									<tr>
										<td>
											<?php $link = get_permalink( $row['page_id'] ); ?>
											<?php if ( $link ) : ?>
												<a href="<?php echo esc_url( $link ); ?>" target="_blank"><?php echo esc_html( get_the_title( $row['page_id'] ) ?: '#' . $row['page_id'] ); ?></a>
											<?php else : ?>
												<?php echo esc_html( '#' . $row['page_id'] ); ?>
											<?php endif; ?>
										</td>
										<?php self::countCells( $row, null ); ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: number of days */
							esc_html__( 'Pages come from detailed events, which are kept for %d days (Ads → Settings).', 'ad-network' ),
							(int) Settings::get( 'retention_days' )
						);
						?>
					</p>
				</div>
			</div>

			<p class="description">
				<?php esc_html_e( 'Served: the ad was put in a slot. Viewed: at least half of it was on screen for a second. Clicks: clicks on the ad\'s links, seen by the page.', 'ad-network' ); ?>
				<?php if ( $has_links ) : ?>
					<?php esc_html_e( 'Link clicks: redirects through the ad\'s cloaked link, from Link Cloaker\'s own log. It also counts clicks the page can\'t see, so it can be higher.', 'ad-network' ); ?>
				<?php endif; ?>
				<?php esc_html_e( 'Totals are updated hourly, and when you open this page.', 'ad-network' ); ?>
			</p>
		</div>
		<?php
	}

	/** CSV of daily totals per ad and placement for the current filters. */
	public static function export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ad-network' ), 403 );
		}
		check_admin_referer( 'adn_export' );
		Rollup::refresh();

		[ $report ] = self::reportFromRequest( wp_unslash( $_GET ) );
		$filename   = sprintf( 'ad-stats-%s-to-%s.csv', $report->from(), $report->to() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		fputcsv( $out, array( 'day', 'ad_id', 'ad', 'size', 'placement', 'served', 'viewed', 'clicks' ) );
		$titles = array();
		foreach ( $report->rows() as $row ) {
			$id = (int) $row['ad_id'];
			if ( ! isset( $titles[ $id ] ) ) {
				$titles[ $id ] = array( self::csvSafe( (string) get_the_title( $id ) ), Ads::meta( $id, 'size' ) );
			}
			fputcsv( $out, array( $row['day'], $id, $titles[ $id ][0], $titles[ $id ][1], $row['placement'], $row['serves'], $row['views'], $row['clicks'] ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Stop spreadsheet apps treating a title as a formula. */
	private static function csvSafe( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * @param array<string, array<string, mixed>> $placements
	 * @return array<string, string>
	 */
	private static function placementLabels( array $placements ): array {
		$out = array_map( static fn ( array $p ): string => (string) $p['label'], $placements );
		$out['manual'] = __( 'Shortcode / template tag', 'ad-network' );
		return $out;
	}

	private static function tile( string $label, string $value, string $note ): void {
		printf(
			'<div class="adn-tile"><div class="adn-tile__label">%s</div><div class="adn-tile__value">%s</div><div class="adn-tile__note">%s</div></div>',
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $note )
		);
	}

	private static function countHeaders( bool $links ): void {
		$cols = array( __( 'Served', 'ad-network' ), __( 'Viewed', 'ad-network' ), __( 'View rate', 'ad-network' ), __( 'Clicks', 'ad-network' ), __( 'CTR', 'ad-network' ) );
		if ( $links ) {
			$cols[] = __( 'Link clicks', 'ad-network' );
		}
		foreach ( $cols as $col ) {
			echo '<th class="num">' . esc_html( $col ) . '</th>';
		}
	}

	/**
	 * @param array<string, int> $t
	 */
	private static function countCells( array $t, ?int $link_clicks ): void {
		$cells = array(
			number_format_i18n( (int) $t['serves'] ),
			number_format_i18n( (int) $t['views'] ),
			Report::viewRate( $t ),
			number_format_i18n( (int) $t['clicks'] ),
			Report::ctr( $t ),
		);
		if ( null !== $link_clicks ) {
			$cells[] = number_format_i18n( $link_clicks );
		}
		foreach ( $cells as $cell ) {
			echo '<td class="num">' . esc_html( $cell ) . '</td>';
		}
	}
}
