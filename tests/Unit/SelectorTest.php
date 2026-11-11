<?php
/**
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Tests\Unit;

use AdNetwork\Serving\Selector;

final class SelectorTest extends TestCase {

	private function ads(): array {
		return array(
			array( 'id' => 1, 'size' => 'leaderboard', 'weight' => 10 ),
			array( 'id' => 2, 'size' => 'leaderboard', 'weight' => 30 ),
			array( 'id' => 3, 'size' => 'medium_rectangle', 'weight' => 10 ),
		);
	}

	public function test_picks_only_matching_size(): void {
		$selector = new Selector( $this->ads() );
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertSame( 3, $selector->fill( array( 's1' => 'medium_rectangle' ) )['s1'] );
		}
	}

	public function test_empty_when_no_ad_fits(): void {
		$selector = new Selector( $this->ads() );
		$this->assertNull( $selector->fill( array( 's1' => 'half_page' ) )['s1'] );
	}

	public function test_never_repeats_an_ad_on_one_page(): void {
		$selector = new Selector( $this->ads() );
		$result   = $selector->fill( array( 'a' => 'leaderboard', 'b' => 'leaderboard', 'c' => 'leaderboard' ) );
		$this->assertEqualsCanonicalizing( array( 1, 2 ), array( $result['a'], $result['b'] ) );
		$this->assertNull( $result['c'] );
	}

	public function test_respects_excluded_ads(): void {
		$selector = new Selector( $this->ads() );
		$this->assertSame( 1, $selector->fill( array( 's' => 'leaderboard' ), array( 2 ) )['s'] );
	}

	public function test_weight_boundaries(): void {
		// Total weight 40: rolls 1–10 pick ad 1, 11–40 pick ad 2.
		$candidates = array_slice( $this->ads(), 0, 2 );
		$this->assertSame( 1, ( new Selector( array(), static fn () => 1 ) )->pick( $candidates ) );
		$this->assertSame( 1, ( new Selector( array(), static fn () => 10 ) )->pick( $candidates ) );
		$this->assertSame( 2, ( new Selector( array(), static fn () => 11 ) )->pick( $candidates ) );
		$this->assertSame( 2, ( new Selector( array(), static fn () => 40 ) )->pick( $candidates ) );
	}

	public function test_weights_shape_the_distribution(): void {
		$selector = new Selector( $this->ads() );
		$counts   = array( 1 => 0, 2 => 0 );
		for ( $i = 0; $i < 4000; $i++ ) {
			++$counts[ $selector->fill( array( 's' => 'leaderboard' ) )['s'] ];
		}
		// Expect about 1:3. Loose bounds keep the test from flaking.
		$this->assertGreaterThan( 2.2, $counts[2] / $counts[1] );
		$this->assertLessThan( 4.0, $counts[2] / $counts[1] );
	}
}
