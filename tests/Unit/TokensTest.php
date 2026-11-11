<?php
/**
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tests\Unit;

use AdNetwork\Tracking\Tokens;

final class TokensTest extends TestCase {

	public function test_round_trip(): void {
		$token = Tokens::issue( 123, 45, 1000000 );
		$this->assertSame( array( 'serve_id' => 123, 'ad_id' => 45, 'time' => 1000000 ), Tokens::verify( $token, 1000100 ) );
	}

	public function test_rejects_tampering(): void {
		$token = Tokens::issue( 123, 45, 1000000 );
		$this->assertNull( Tokens::verify( str_replace( '123.45.', '124.45.', $token ), 1000100 ) );
		$this->assertNull( Tokens::verify( str_replace( '123.45.', '123.46.', $token ), 1000100 ) );
		$this->assertNull( Tokens::verify( 'garbage', 1000100 ) );
		$this->assertNull( Tokens::verify( '', 1000100 ) );
	}

	public function test_expires(): void {
		$token = Tokens::issue( 1, 2, 1000000 );
		$this->assertNotNull( Tokens::verify( $token, 1000000 + Tokens::TTL ) );
		$this->assertNull( Tokens::verify( $token, 1000000 + Tokens::TTL + 1 ) );
		$this->assertNull( Tokens::verify( $token, 1000000 - 301 ), 'Tokens from the future are refused.' );
	}

	public function test_depends_on_the_site_salt(): void {
		$token = Tokens::issue( 1, 2, 1000000 );
		\Brain\Monkey\Functions\when( 'wp_salt' )->justReturn( 'another-site' );
		$this->assertNull( Tokens::verify( $token, 1000000 ) );
	}
}
