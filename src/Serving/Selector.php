<?php
/**
 * Picks ads for slots: weighted random, never the same ad twice on one page.
 *
 * Pure logic with no WordPress calls, so it is unit-tested directly.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Serving;

final class Selector {

	/** @var callable(int, int): int */
	private $random;

	/**
	 * @param list<array{id: int, size: string, weight: int}> $ads    Servable ads.
	 * @param callable(int, int): int|null                    $random Random integer in [min, max]; random_int by default.
	 */
	public function __construct( private array $ads, ?callable $random = null ) {
		$this->random = $random ?? 'random_int';
	}

	/**
	 * Fill slots in order. A slot with no eligible ad left gets null.
	 *
	 * @param array<string, string> $slots   Slot ID => size key.
	 * @param list<int>             $exclude Ad IDs already on the page.
	 * @return array<string, int|null> Slot ID => ad ID.
	 */
	public function fill( array $slots, array $exclude = array() ): array {
		$used = array_fill_keys( $exclude, true );
		$out  = array();
		foreach ( $slots as $slot_id => $size ) {
			$candidates = array_values(
				array_filter(
					$this->ads,
					static fn ( array $ad ): bool => $ad['size'] === $size && ! isset( $used[ $ad['id'] ] )
				)
			);
			$pick              = $this->pick( $candidates );
			$out[ $slot_id ] = $pick;
			if ( null !== $pick ) {
				$used[ $pick ] = true;
			}
		}
		return $out;
	}

	/**
	 * Weighted random choice.
	 *
	 * @param list<array{id: int, size: string, weight: int}> $candidates
	 */
	public function pick( array $candidates ): ?int {
		if ( ! $candidates ) {
			return null;
		}
		$total = 0;
		foreach ( $candidates as $ad ) {
			$total += max( 1, (int) $ad['weight'] );
		}
		$roll = ( $this->random )( 1, $total );
		foreach ( $candidates as $ad ) {
			$roll -= max( 1, (int) $ad['weight'] );
			if ( $roll <= 0 ) {
				return (int) $ad['id'];
			}
		}
		return (int) $candidates[ count( $candidates ) - 1 ]['id'];
	}
}
