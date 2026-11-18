<?php
/**
 * Columns on the Ads list: size, type, link and performance.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Admin;

use AdNetwork\Ads;
use AdNetwork\Integrations\LinkCloaker;
use AdNetwork\Sizes;
use AdNetwork\Stats\Report;
use AdNetwork\Tracking\Rollup;

final class AdList {

	/** The list's stats columns cover this many days. */
	public const DAYS = 30;

	/** @var array<int, array{serves: int, views: int, clicks: int}>|null */
	private static ?array $totals = null;

	public static function init(): void {
		add_filter( 'manage_' . Ads::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Ads::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'display_post_states', array( self::class, 'states' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'rowActions' ), 10, 2 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['adn_size']   = __( 'Size', 'ad-network' );
				$out['adn_type']   = __( 'Type', 'ad-network' );
				$out['adn_link']   = __( 'Link', 'ad-network' );
				$out['adn_weight'] = __( 'Weight', 'ad-network' );
				/* translators: %d: number of days */
				$days              = sprintf( __( '%d days', 'ad-network' ), self::DAYS );
				$out['adn_serve']  = __( 'Served', 'ad-network' ) . '<br><span class="description">' . esc_html( $days ) . '</span>';
				$out['adn_view']   = __( 'Viewed', 'ad-network' );
				$out['adn_click']  = __( 'Clicks', 'ad-network' );
				$out['adn_ctr']    = __( 'CTR', 'ad-network' );
			}
		}
		$out['date'] = $columns['date'] ?? __( 'Date', 'ad-network' );
		return $out;
	}

	public static function column( string $column, int $ad_id ): void {
		switch ( $column ) {
			case 'adn_size':
				$size = Ads::meta( $ad_id, 'size' );
				echo esc_html( '' === $size ? '—' : Sizes::label( $size ) );
				if ( '' !== $size && ! Sizes::isEnabled( $size ) ) {
					echo '<br><span class="description">' . esc_html__( 'Size turned off', 'ad-network' ) . '</span>';
				}
				break;
			case 'adn_type':
				echo esc_html( Ads::TYPE_HTML === Ads::type( $ad_id ) ? ( Ads::isThirdParty( $ad_id ) ? __( 'Third-party code', 'ad-network' ) : __( 'HTML', 'ad-network' ) ) : __( 'Image', 'ad-network' ) );
				break;
			case 'adn_link':
				$link_id = (int) Ads::meta( $ad_id, 'link_id' );
				if ( $link_id && LinkCloaker::available() ) {
					printf( '<a href="%s">%s</a>', esc_url( LinkCloaker::editUrl( $link_id ) ), esc_html( get_the_title( $link_id ) ) );
				} else {
					$url = Ads::meta( $ad_id, 'url' );
					echo '' !== $url ? esc_html( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '—';
				}
				break;
			case 'adn_weight':
				echo esc_html( (string) Ads::weight( $ad_id ) );
				break;
			case 'adn_serve':
			case 'adn_view':
			case 'adn_click':
				$t = self::totalsFor( $ad_id );
				echo esc_html( number_format_i18n( $t[ substr( $column, 4 ) . 's' ] ) );
				break;
			case 'adn_ctr':
				echo esc_html( Report::ctr( self::totalsFor( $ad_id ) ) );
				break;
		}
	}

	/**
	 * @param array<string, string> $states
	 * @return array<string, string>
	 */
	public static function states( array $states, \WP_Post $post ): array {
		if ( Ads::POST_TYPE !== $post->post_type ) {
			return $states;
		}
		if ( 'draft' === $post->post_status ) {
			$states['draft'] = __( 'Paused', 'ad-network' );
		} elseif ( 'publish' === $post->post_status && ! Ads::isComplete( $post->ID ) ) {
			$states['adn_incomplete'] = __( 'Incomplete', 'ad-network' );
		}
		return $states;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function rowActions( array $actions, \WP_Post $post ): array {
		if ( Ads::POST_TYPE === $post->post_type && 'auto-draft' !== $post->post_status && current_user_can( StatsPage::CAPABILITY ) ) {
			$actions['adn_stats'] = sprintf( '<a href="%s">%s</a>', esc_url( StatsPage::url( array( 'ad' => $post->ID ) ) ), esc_html__( 'Stats', 'ad-network' ) );
		}
		return $actions;
	}

	/** @return array{serves: int, views: int, clicks: int} */
	private static function totalsFor( int $ad_id ): array {
		if ( null === self::$totals ) {
			global $wp_query;
			Rollup::refresh();
			$ids          = $wp_query ? array_map( 'intval', wp_list_pluck( (array) $wp_query->posts, 'ID' ) ) : array();
			self::$totals = Report::lastDays( self::DAYS )->forAds( $ids );
		}
		return self::$totals[ $ad_id ] ?? Report::lastDays( self::DAYS )->forAds( array( $ad_id ) )[ $ad_id ];
	}
}
