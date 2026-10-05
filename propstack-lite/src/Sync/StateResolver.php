<?php

namespace PropstackLite\Sync;

use PropstackLite\Storage\StoredProperty;

/**
 * Entscheidet, was mit einem Objekt im lokalen Bestand passiert (reine Logik, ohne I/O).
 *
 * Regeln (docs/sync.md):
 * - Öffentlicher Status und nicht archiviert            → UPSERT (auch Reaktivierung)
 * - Status „verkauft/vermietet“, zuvor aktiv            → SOLD (30-Tage-Anzeige, danach 410)
 * - zuvor verkauft, jetzt anderer Status/archiviert     → KEEP (30-Tage-Frist läuft weiter)
 * - sonst, zuvor aktiv oder verkauft                    → REMOVE (sofort 410)
 * - in Propstack gelöscht (404)                          → REMOVE, falls gespeichert
 * - nie öffentlich gewesen                               → IGNORE (wird nie gespeichert)
 */
final class StateResolver {

	public const UPSERT = 'upsert';
	public const SOLD   = 'sold';
	public const KEEP   = 'keep';
	public const REMOVE = 'remove';
	public const IGNORE = 'ignore';

	/**
	 * @param list<int> $publicStatusIds
	 * @param list<int> $soldStatusIds
	 */
	public function __construct( private array $publicStatusIds, private array $soldStatusIds ) {}

	public function resolve( ?int $statusId, bool $archived, bool $deleted, ?string $storedState ): string {
		$wasVisible = StoredProperty::STATE_ACTIVE === $storedState || StoredProperty::STATE_SOLD === $storedState;

		if ( $deleted ) {
			return $wasVisible ? self::REMOVE : self::IGNORE;
		}

		if ( ! $archived && null !== $statusId && in_array( $statusId, $this->publicStatusIds, true ) ) {
			return self::UPSERT;
		}

		if ( StoredProperty::STATE_SOLD === $storedState ) {
			return self::KEEP;
		}

		if ( StoredProperty::STATE_ACTIVE === $storedState && null !== $statusId && in_array( $statusId, $this->soldStatusIds, true ) ) {
			return self::SOLD;
		}

		return $wasVisible ? self::REMOVE : self::IGNORE;
	}
}
