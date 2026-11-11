<?php
/**
 * Signed tokens that tie a browser's view/click reports to the ad the server actually served.
 *
 * Token format: "{serve event ID}.{ad ID}.{unix time}.{signature}". The signature stops anyone
 * reporting views or clicks for ads they were never shown, which matters once views and clicks
 * earn credits.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tracking;

final class Tokens {

	/** How long after serving an ad its views and clicks still count. */
	public const TTL = DAY_IN_SECONDS;

	public static function issue( int $serve_id, int $ad_id, ?int $time = null ): string {
		$payload = $serve_id . '.' . $ad_id . '.' . ( $time ?? time() );
		return $payload . '.' . self::sign( $payload );
	}

	/**
	 * @return array{serve_id: int, ad_id: int, time: int}|null The token's contents, or null if it is
	 *                                                          malformed, forged or expired.
	 */
	public static function verify( string $token, ?int $now = null ): ?array {
		if ( ! preg_match( '/^(\d{1,20})\.(\d{1,20})\.(\d{1,12})\.([a-f0-9]{32})$/', $token, $m ) ) {
			return null;
		}
		$payload = $m[1] . '.' . $m[2] . '.' . $m[3];
		if ( ! hash_equals( self::sign( $payload ), $m[4] ) ) {
			return null;
		}
		$now  = $now ?? time();
		$time = (int) $m[3];
		if ( $time > $now + 300 || $now - $time > self::TTL ) {
			return null;
		}
		return array(
			'serve_id' => (int) $m[1],
			'ad_id'    => (int) $m[2],
			'time'     => $time,
		);
	}

	private static function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', 'adn|' . $payload, wp_salt( 'auth' ) ), 0, 32 );
	}
}
