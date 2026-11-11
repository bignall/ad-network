<?php
/**
 * In-content insertion and active-code detection.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tests\Unit;

use AdNetwork\Ads;
use AdNetwork\Serving\Frontend;
use AdNetwork\Tracking\Visitor;

final class ContentTest extends TestCase {

	public function test_inserts_after_nth_paragraph(): void {
		$content = '<p>One</p><p>Two</p><p>Three</p><p>Four</p>';
		$this->assertSame( '<p>One</p><p>Two</p>[AD]<p>Three</p><p>Four</p>', Frontend::insertAfterParagraph( $content, '[AD]', 2 ) );
	}

	public function test_short_posts_get_no_ad(): void {
		$content = '<p>One</p><p>Two</p>';
		$this->assertSame( $content, Frontend::insertAfterParagraph( $content, '[AD]', 2 ) );
		$this->assertSame( $content, Frontend::insertAfterParagraph( $content, '[AD]', 5 ) );
	}

	public function test_handles_uppercase_and_attributes(): void {
		$content = "<p class=\"x\">One</P>\n<p>Two</p>";
		$this->assertSame( "<p class=\"x\">One</P>[AD]\n<p>Two</p>", Frontend::insertAfterParagraph( $content, '[AD]', 1 ) );
	}

	/** @dataProvider activeCode */
	public function test_detects_active_code( string $html, bool $active ): void {
		$this->assertSame( $active, Ads::containsActiveCode( $html ) );
	}

	public static function activeCode(): array {
		return array(
			'plain link'      => array( '<a href="{{url}}"><strong>Buy</strong></a>', false ),
			'adsense'         => array( '<script async src="https://pagead2.googlesyndication.com/x.js"></script><ins class="adsbygoogle"></ins>', true ),
			'iframe'          => array( '<IFRAME src="https://x.test"></IFRAME>', true ),
			'event handler'   => array( '<img src="x.png" onerror="alert(1)">', true ),
			'js url'          => array( '<a href="javascript:alert(1)">x</a>', true ),
			'style tag'       => array( '<style>body{display:none}</style>', true ),
			'word "onion"'    => array( '<p>An onion = tasty</p>', false ),
		);
	}

	/** @dataProvider userAgents */
	public function test_bot_detection( string $ua, bool $bot ): void {
		$this->assertSame( $bot, Visitor::isBot( $ua ) );
	}

	public static function userAgents(): array {
		return array(
			array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', false ),
			array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true ),
			array( 'Mozilla/5.0 HeadlessChrome/120.0', true ),
			array( '', true ),
		);
	}
}
