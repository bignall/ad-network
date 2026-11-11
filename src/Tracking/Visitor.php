<?php
/**
 * Who is looking at the page, as far as tracking needs to know: a bot? an admin? and a salted,
 * daily-rotating hash that tells visitors apart without storing their IP address.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tracking;

use AdNetwork\Settings;

final class Visitor {

	public static function isBot( ?string $user_agent = null ): bool {
		$ua = strtolower( $user_agent ?? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python-requests|headless|lighthouse|pagespeed|gtmetrix/', $ua );
	}

	/** Whether this request's events should be recorded. */
	public static function shouldTrack(): bool {
		$s = Settings::all();
		if ( ! $s['track'] ) {
			return false;
		}
		if ( $s['ignore_bots'] && self::isBot() ) {
			return false;
		}
		if ( ! $s['track_admins'] && current_user_can( 'edit_others_posts' ) ) {
			return false;
		}
		/**
		 * Filter whether to record ad events for the current request.
		 *
		 * @param bool $track
		 */
		return (bool) apply_filters( 'adn_should_track', true );
	}

	/**
	 * A hash of IP + user agent, salted and rotated daily: enough to spot one visitor generating
	 * many events in a day, never enough to recover the IP.
	 */
	public static function hash(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( '' === $ip ) {
			return '';
		}
		return hash_hmac( 'sha256', $ip . '|' . $ua . '|' . gmdate( 'Y-m-d' ), wp_salt( 'auth' ) );
	}
}
