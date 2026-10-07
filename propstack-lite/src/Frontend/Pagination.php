<?php

namespace PropstackLite\Frontend;

/**
 * Seitennavigation der Immobilienliste (ohne WordPress testbar): Seitenzahl, Vor/Zurück und
 * eine kompakte Seitenliste mit Auslassungen („1 … 4 5 6 … 20“).
 */
final class Pagination {

	public readonly int $pages;

	public function __construct(
		public readonly int $total,
		public readonly int $perPage,
		public readonly int $current
	) {
		$this->pages = max( 1, (int) ceil( max( 0, $total ) / max( 1, $perPage ) ) );
	}

	public function isOutOfRange(): bool {
		return $this->current > $this->pages;
	}

	/** Navigation nötig? (mehr als eine Seite oder Aufruf jenseits der letzten Seite) */
	public function isNeeded(): bool {
		return $this->pages > 1 && ! $this->isOutOfRange();
	}

	public function previous(): ?int {
		return $this->current > 1 && ! $this->isOutOfRange() ? $this->current - 1 : null;
	}

	public function next(): ?int {
		return $this->current < $this->pages ? $this->current + 1 : null;
	}

	/** Erster/letzter Treffer der Seite (1-basiert) für „Immobilien 13–24 von 40“. @return array{0: int, 1: int} */
	public function range(): array {
		if ( 0 === $this->total || $this->isOutOfRange() ) {
			return [ 0, 0 ];
		}
		$first = ( $this->current - 1 ) * $this->perPage + 1;
		return [ $first, min( $this->total, $first + $this->perPage - 1 ) ];
	}

	/**
	 * Seitenliste: erste, letzte und die Nachbarn der aktuellen Seite; Lücken als null.
	 *
	 * @return list<int|null>
	 */
	public function items( int $neighbours = 1 ): array {
		if ( ! $this->isNeeded() ) {
			return [];
		}
		$show = [ 1, $this->pages ];
		for ( $i = $this->current - $neighbours; $i <= $this->current + $neighbours; $i++ ) {
			$show[] = $i;
		}
		$show = array_values( array_unique( array_filter( $show, fn ( $n ) => $n >= 1 && $n <= $this->pages ) ) );
		sort( $show );

		$items = [];
		$prev  = 0;
		foreach ( $show as $n ) {
			if ( $n - $prev === 2 ) {
				$items[] = $n - 1; // einzelne Lücke direkt anzeigen statt „…“
			} elseif ( $n - $prev > 2 ) {
				$items[] = null;
			}
			$items[] = $n;
			$prev    = $n;
		}
		return $items;
	}
}
