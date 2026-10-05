<?php

namespace PropstackLite\Routing;

use PropstackLite\Storage\StoredProperty;
use PropstackLite\Sync\SyncService;

/**
 * Bestimmt aus dem gespeicherten Zustand, wie eine Detail-URL beantwortet wird.
 * Reine Logik ohne I/O – Grundlage ist ausschließlich der lokale Store, nie die Propstack-API.
 *
 * | Zeile / Zustand                                        | Ergebnis              |
 * |--------------------------------------------------------|-----------------------|
 * | keine Zeile (nie öffentlich gewesen)                   | 404 not_found         |
 * | active, Status öffentlich, Daten vorhanden             | 200 active / reserved |
 * | active, Status inzwischen nicht mehr öffentlich        | 410 gone              |
 * | sold, sold_at jünger als 30 Tage, Daten vorhanden      | 200 sold (noindex)    |
 * | sold, älter als 30 Tage oder ohne Daten                | 410 gone              |
 * | removed                                                | 410 gone              |
 */
final class RouteResolver {

	/**
	 * @param list<int> $publicStatusIds
	 * @param list<int> $reservedStatusIds
	 */
	public function __construct(
		private array $publicStatusIds,
		private array $reservedStatusIds,
		private \DateTimeImmutable $now,
		private int $soldRetentionDays = SyncService::SOLD_RETENTION_DAYS
	) {}

	public function resolve( ?StoredProperty $stored ): RouteDecision {
		if ( null === $stored ) {
			return new RouteDecision( RouteDecision::NOT_FOUND, 404 );
		}

		if ( StoredProperty::STATE_ACTIVE === $stored->state ) {
			$public = null !== $stored->statusId && in_array( $stored->statusId, $this->publicStatusIds, true );
			if ( ! $public || null === $stored->property ) {
				return new RouteDecision( RouteDecision::GONE, 410, $stored );
			}
			$reserved = in_array( $stored->statusId, $this->reservedStatusIds, true );
			return new RouteDecision( $reserved ? RouteDecision::RESERVED : RouteDecision::ACTIVE, 200, $stored );
		}

		if ( StoredProperty::STATE_SOLD === $stored->state && null !== $stored->property && $this->withinSoldWindow( $stored->soldAt ) ) {
			return new RouteDecision( RouteDecision::SOLD, 200, $stored );
		}

		return new RouteDecision( RouteDecision::GONE, 410, $stored );
	}

	/** `sold_at` ist UTC (`Y-m-d H:i:s`); Frist läuft ab dem ersten Verkauft-Zeitpunkt. */
	private function withinSoldWindow( ?string $soldAt ): bool {
		if ( null === $soldAt || '' === $soldAt ) {
			return false;
		}
		try {
			$sold = new \DateTimeImmutable( $soldAt, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return false;
		}
		return $sold->add( new \DateInterval( 'P' . $this->soldRetentionDays . 'D' ) ) > $this->now;
	}
}
