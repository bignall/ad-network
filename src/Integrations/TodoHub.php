<?php
/**
 * Optional link-up with To-do Hub: Ad Network adds tasks to your to-do list when ads need
 * attention, and marks them done when they're fixed.
 *
 * - "Fix ad <title>" for each published ad that won't show (no image, no link, its size turned
 *   off, third-party code not allowed) or whose cloaked link is gone or no longer pays. High
 *   priority when the offer no longer pays, because the ad keeps using up the slot.
 * - "Add ads for N empty ad slots" when a placement that's turned on has no ad to show at a size
 *   it uses (desktop or phone), so visitors see a blank space.
 *
 * Checked when an ad is saved or changes status, when settings change, and once a day (an
 * affiliate network can end an offer without anything here being saved). To-do Hub is only called
 * when a task actually changes. Works with To-do Hub as the hub on this site or as a connector.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Integrations;

use AdNetwork\Ads;
use AdNetwork\Placements;
use AdNetwork\Settings;
use AdNetwork\Sizes;

final class TodoHub {

	public const SOURCE    = 'ad-network';
	public const CRON_HOOK = 'adn_todo_check';
	public const CATEGORY  = 'Ad Network';

	/** On each ad: fingerprint of the problems last sent, so unchanged problems aren't resent. */
	public const META_SENT = '_adn_todo_sent';

	/** Fingerprint of the empty slots last sent. */
	public const SLOTS_OPTION = 'adn_todo_slots_sent';

	/** @var array<int, true> Ads to re-check at the end of this request. */
	private static array $dirty = array();

	private static bool $slots_dirty = false;

	public static function init(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'onSaved' ), 20, 2 );
		add_action( 'transition_post_status', array( self::class, 'onTransition' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'onDelete' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'onSettingsChanged' ) );
		add_action( self::CRON_HOOK, array( self::class, 'checkAll' ) );
		add_action( 'todo_hub_task_completed_' . self::SOURCE, array( self::class, 'onCompleted' ) );
		add_action( 'shutdown', array( self::class, 'flush' ) );
	}

	/** Whether To-do Hub is active and the setting is on. */
	public static function enabled(): bool {
		return function_exists( 'todo_hub_add' ) && (bool) Settings::get( 'todo_tasks' );
	}

	public static function adKey( int $ad_id ): string {
		return self::SOURCE . ':ad:' . $ad_id;
	}

	public static function slotsKey(): string {
		return self::SOURCE . ':empty-slots';
	}

	public static function onSaved( int $post_id, \WP_Post $post ): void {
		if ( Ads::POST_TYPE === $post->post_type && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
			self::$dirty[ $post_id ] = true;
			self::$slots_dirty       = true;
		}
	}

	public static function onTransition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( Ads::POST_TYPE === $post->post_type && $new_status !== $old_status ) {
			self::$dirty[ $post->ID ] = true;
			self::$slots_dirty        = true;
		}
	}

	public static function onDelete( int $post_id ): void {
		if ( get_post_type( $post_id ) === Ads::POST_TYPE ) {
			self::check( $post_id, true );
			self::$slots_dirty = true;
		}
	}

	/** Sizes, placements and the third-party switch decide what shows: check again soon. */
	public static function onSettingsChanged(): void {
		if ( self::enabled() && ! wp_next_scheduled( self::CRON_HOOK, array( 'settings' ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( 'settings' ) );
		}
	}

	/** Re-check what this request changed, once, after everything is saved. */
	public static function flush(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$dirty       = array_keys( self::$dirty );
		self::$dirty = array();
		if ( $dirty || self::$slots_dirty ) {
			// Ads' servable list is cached; make sure the checks see this request's changes.
			Ads::flushCache();
		}
		foreach ( $dirty as $ad_id ) {
			self::check( (int) $ad_id );
		}
		if ( self::$slots_dirty ) {
			self::$slots_dirty = false;
			self::checkSlots();
		}
	}

	/** Daily, and after settings change: check every ad and every slot. */
	public static function checkAll(): void {
		if ( ! self::enabled() ) {
			return;
		}
		Ads::flushCache();
		$ids = get_posts(
			array(
				'post_type'        => Ads::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			self::check( (int) $id );
		}
		self::checkSlots();
	}

	/**
	 * What's wrong with a published ad, one line per problem, and whether it's urgent.
	 *
	 * @return array{problems: list<string>, urgent: bool}
	 */
	public static function problems( int $ad_id ): array {
		$problems = array();
		$urgent   = false;
		$size     = Ads::meta( $ad_id, 'size' );

		if ( ! Sizes::exists( $size ) ) {
			$problems[] = __( 'No ad size is chosen, so it never shows.', 'ad-network' );
		} elseif ( ! Sizes::isEnabled( $size ) ) {
			/* translators: %s: ad size, e.g. "Leaderboard (728×90)". */
			$problems[] = sprintf( __( 'Its size, %s, is turned off in Ads → Settings, so it never shows.', 'ad-network' ), Sizes::label( $size ) );
		}

		if ( Ads::TYPE_HTML === Ads::type( $ad_id ) ) {
			if ( '' === trim( Ads::meta( $ad_id, 'html' ) ) ) {
				$problems[] = __( 'The HTML is empty, so it never shows.', 'ad-network' );
			}
			if ( Ads::isThirdParty( $ad_id ) && ! Settings::scriptsAllowed() ) {
				$problems[] = __( 'It has third-party code, which Ads → Settings doesn’t allow, so it never shows.', 'ad-network' );
			}
		} else {
			if ( (int) Ads::meta( $ad_id, 'image_id' ) <= 0 ) {
				$problems[] = __( 'No image is chosen, so it never shows.', 'ad-network' );
			}
			if ( '' === Ads::clickUrl( $ad_id ) ) {
				$problems[] = __( 'It has no link: choose a cloaked link or enter a web address. Until then it never shows.', 'ad-network' );
			}
		}

		$link_id = (int) Ads::meta( $ad_id, 'link_id' );
		if ( $link_id > 0 && LinkCloaker::available() ) {
			if ( '' === LinkCloaker::url( $link_id ) ) {
				$problems[] = __( 'The cloaked link it uses was deleted or unpublished.', 'ad-network' );
			} elseif ( LinkCloaker::isDead( $link_id ) ) {
				$problems[] = __( 'The affiliate network says this offer no longer pays (paused, banned or ended), but the ad still shows.', 'ad-network' );
				$urgent     = true;
			}
		}

		return array(
			'problems' => $problems,
			'urgent'   => $urgent,
		);
	}

	/**
	 * Add, update or close the ad's task to match the ad now.
	 *
	 * @param bool $gone The ad is being deleted: close its task.
	 */
	public static function check( int $ad_id, bool $gone = false ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$sent   = (string) get_post_meta( $ad_id, self::META_SENT, true );
		$live   = ! $gone && 'publish' === get_post_status( $ad_id );
		$report = $live ? self::problems( $ad_id ) : array(
			'problems' => array(),
			'urgent'   => false,
		);

		if ( ! $report['problems'] ) {
			if ( '' !== $sent ) {
				// Fixed: done. Paused (draft) or deleted: it no longer needs doing.
				if ( $live ) {
					todo_hub_complete( self::adKey( $ad_id ) );
				} else {
					todo_hub_remove( self::adKey( $ad_id ) );
				}
				delete_post_meta( $ad_id, self::META_SENT );
			}
			return;
		}

		$fingerprint = md5( (string) wp_json_encode( $report ) );
		if ( $fingerprint === $sent ) {
			return;
		}
		$result = todo_hub_add(
			array(
				/* translators: %s: ad title. */
				'title'    => sprintf( __( 'Fix ad “%s”', 'ad-network' ), (string) get_the_title( $ad_id ) ),
				'notes'    => '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $report['problems'] ) ) . '</li></ul>',
				'category' => self::CATEGORY,
				'priority' => $report['urgent'] ? 'high' : 'normal',
				'source'   => self::SOURCE,
				'key'      => self::adKey( $ad_id ),
				'link'     => (string) get_edit_post_link( $ad_id, 'raw' ),
				'notify'   => true,
				'data'     => array( 'ad_id' => $ad_id ),
			)
		);
		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $ad_id, self::META_SENT, $fingerprint );
		}
	}

	/**
	 * Placements that are on but have no ad to show at a size they use.
	 *
	 * @return list<string> E.g. "Header banner on phones: Mobile banner (320×50)".
	 */
	public static function emptySlots(): array {
		$have = array();
		foreach ( Ads::servable() as $ad ) {
			$have[ $ad['size'] ] = true;
		}
		$empty = array();
		foreach ( Placements::all() as $key => $placement ) {
			if ( ! Placements::isEnabled( $key ) ) {
				continue;
			}
			$config = (array) Placements::config( $key );
			$sizes  = array(
				'desktop' => (string) ( $config['desktop'] ?? '' ),
				'mobile'  => (string) ( $config['mobile'] ?? '' ),
			);
			if ( $sizes['desktop'] === $sizes['mobile'] && '' !== $sizes['desktop'] ) {
				if ( ! isset( $have[ $sizes['desktop'] ] ) ) {
					/* translators: 1: placement name, 2: ad size. */
					$empty[] = sprintf( __( '%1$s: %2$s', 'ad-network' ), $placement['label'], Sizes::label( $sizes['desktop'] ) );
				}
				continue;
			}
			foreach ( $sizes as $device => $size ) {
				if ( '' === $size || isset( $have[ $size ] ) ) {
					continue;
				}
				$empty[] = 'desktop' === $device
					/* translators: 1: placement name, 2: ad size. */
					? sprintf( __( '%1$s on computers: %2$s', 'ad-network' ), $placement['label'], Sizes::label( $size ) )
					/* translators: 1: placement name, 2: ad size. */
					: sprintf( __( '%1$s on phones: %2$s', 'ad-network' ), $placement['label'], Sizes::label( $size ) );
			}
		}
		return $empty;
	}

	/** Keep "Add ads for N empty ad slots" in step with the placements and ads. */
	public static function checkSlots(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$empty       = self::emptySlots();
		$fingerprint = $empty ? md5( (string) wp_json_encode( $empty ) ) : '';
		$sent        = (string) get_option( self::SLOTS_OPTION, '' );
		if ( $fingerprint === $sent ) {
			return;
		}
		if ( ! $empty ) {
			todo_hub_complete( self::slotsKey() );
		} else {
			$result = todo_hub_add(
				array(
					/* translators: %s: number of slots. */
					'title'    => sprintf( _n( 'Add ads for %s empty ad slot', 'Add ads for %s empty ad slots', count( $empty ), 'ad-network' ), number_format_i18n( count( $empty ) ) ),
					'notes'    => '<p>' . esc_html__( 'These placements are on but have no ad of the size they use, so visitors see a blank space:', 'ad-network' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $empty ) ) . '</li></ul>',
					'category' => self::CATEGORY,
					'priority' => 'high',
					'source'   => self::SOURCE,
					'key'      => self::slotsKey(),
					'link'     => admin_url( 'post-new.php?post_type=' . Ads::POST_TYPE ),
					'notify'   => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				return;
			}
		}
		update_option( self::SLOTS_OPTION, $fingerprint, false );
	}

	/**
	 * A task was ticked off on the to-do list. If the problem is still there, the next check adds
	 * it again: forget what was sent so it isn't skipped as unchanged.
	 *
	 * @param array<string, mixed> $task The completed task.
	 */
	public static function onCompleted( array $task ): void {
		$key = (string) ( $task['key'] ?? '' );
		if ( self::slotsKey() === $key ) {
			delete_option( self::SLOTS_OPTION );
			return;
		}
		$ad_id = (int) ( $task['data']['ad_id'] ?? 0 );
		if ( $ad_id > 0 && self::adKey( $ad_id ) === $key ) {
			delete_post_meta( $ad_id, self::META_SENT );
		}
	}
}
