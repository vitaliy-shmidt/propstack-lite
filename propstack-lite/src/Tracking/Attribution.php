<?php

namespace PropstackLite\Tracking;

/**
 * Attribution eines Besuchers: First Touch (erster nicht-direkter Kontakt, bleibt bestehen) und
 * Last Non-Direct Touch (letzter nicht-direkter Kontakt; Direktaufrufe überschreiben ihn nicht).
 */
final class Attribution {

	/** Propstack-Zuordnungsschlüssel (client_cf_*), die aus der Attribution befüllt werden können. */
	public const LEAD_FIELDS = [
		'first_utm_source', 'first_utm_medium', 'first_utm_campaign',
		'last_utm_source', 'last_utm_medium', 'last_utm_campaign',
		'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid', 'landing_path', 'referrer_host',
	];

	public function __construct(
		public readonly ?Touch $first,
		public readonly ?Touch $last
	) {}

	public function isEmpty(): bool {
		return null === $this->first && null === $this->last;
	}

	/**
	 * Werte für die optionale Propstack-Übertragung (nur nicht-leere Werte).
	 * - first_* / last_*: Quelle, Medium, Kampagne des jeweiligen Touches
	 * - utm_content / utm_term und Klick-IDs: vom letzten Touch, sonst vom ersten
	 * - landing_path / referrer_host: vom ersten Touch (wie kam der Besucher ursprünglich)
	 *
	 * @return array<string, string>
	 */
	public function leadFields(): array {
		$f      = $this->first;
		$l      = $this->last ?? $this->first;
		$pick   = static fn ( string $prop ) => ( null !== $l && '' !== $l->$prop ) ? $l->$prop : ( null !== $f ? $f->$prop : '' );
		$fields = [
			'first_utm_source'   => $f?->source ?? '',
			'first_utm_medium'   => $f?->medium ?? '',
			'first_utm_campaign' => $f?->campaign ?? '',
			'last_utm_source'    => $l?->source ?? '',
			'last_utm_medium'    => $l?->medium ?? '',
			'last_utm_campaign'  => $l?->campaign ?? '',
			'utm_content'        => $pick( 'content' ),
			'utm_term'           => $pick( 'term' ),
			'gclid'              => $pick( 'gclid' ),
			'gbraid'             => $pick( 'gbraid' ),
			'wbraid'             => $pick( 'wbraid' ),
			'landing_path'       => $f?->landingPath ?? '',
			'referrer_host'      => $f?->referrerHost ?? '',
		];
		return array_filter( $fields, static fn ( string $v ) => '' !== $v );
	}
}
