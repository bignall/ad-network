<?php
/**
 * Base test case with Brain Monkey set up.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
