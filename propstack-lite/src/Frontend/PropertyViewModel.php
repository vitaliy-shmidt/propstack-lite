<?php

namespace PropstackLite\Frontend;

use PropstackLite\Domain\Property;
use PropstackLite\Routing\RouteDecision;

/**
 * Bereitet die Daten der Detailseite für Templates auf.
 *
 * Enthält ausschließlich Werte aus dem gewhitelisteten Property-Modell, unescaped –
 * das Escaping erfolgt im Template. Rich Text (`description`) ist beim Speichern
 * bereits mit wp_kses_post bereinigt und wird bei der Ausgabe erneut gefiltert.
 */
final class PropertyViewModel {

	/** @return array<string, mixed> */
	public static function build( Property $p, RouteDecision $decision, string $canonicalUrl, string $overviewUrl ): array {
		$image = $p->mainImage();
		$title = Formatter::title( $p );
		$area  = $p->mainArea();
		$agent = $p->agent;

		$facts = [ Formatter::priceRow( $p ) ];
		if ( null !== $area ) {
			$facts[] = [ 'label' => null !== $p->livingSpace ? 'Wohnfläche' : 'Fläche', 'value' => (string) Formatter::area( $area ) ];
		}
		if ( null !== $p->rooms ) {
			$facts[] = [ 'label' => 'Zimmer', 'value' => Formatter::decimal( $p->rooms, 1 ) ];
		}
		if ( null !== $p->plotArea ) {
			$facts[] = [ 'label' => 'Grundstück', 'value' => (string) Formatter::area( $p->plotArea ) ];
		}

		return [
			'id'           => $p->id,
			'state'        => $decision->state,
			'title'        => $title,
			'type'         => Formatter::typeLabel( $p->rsType ),
			'marketing'    => Formatter::marketingLabel( $p->marketingType ),
			'location'     => $p->address->publicLabel(),
			'statusBadge'  => self::statusBadge( $p, $decision ),
			'facts'        => $facts,
			'image'        => null === $image ? null : [
				'src' => $image->src( 'big' ),
				'alt' => $image->title ?? $title,
			],
			'imageCount'   => count( $p->images ),
			'description'  => $p->texts['description'] ?? null,
			'agent'        => null === $agent ? null : [
				'name'      => $agent->displayName(),
				'position'  => $agent->position,
				'avatarUrl' => $agent->avatarUrl,
				'email'     => $agent->email,
				'phone'     => $agent->phone,
				'cell'      => $agent->cell,
			],
			'canonicalUrl' => $canonicalUrl,
			'overviewUrl'  => $overviewUrl,
			'allowContact' => $decision->allowsContact(),
			'isSold'       => RouteDecision::SOLD === $decision->state,
		];
	}

	/** @return array{label: string, modifier: string}|null */
	public static function statusBadge( Property $p, RouteDecision $decision ): ?array {
		$badge = match ( $decision->state ) {
			RouteDecision::SOLD     => [ 'label' => $p->isRent() ? 'Vermietet' : 'Verkauft', 'modifier' => 'sold' ],
			RouteDecision::RESERVED => [ 'label' => 'Reserviert', 'modifier' => 'reserved' ],
			default                 => null,
		};
		if ( null === $badge ) {
			return null;
		}
		$label = function_exists( 'apply_filters' )
			? (string) apply_filters( 'psl_property_status_label', $badge['label'], $decision->state, $p )
			: $badge['label'];
		return [ 'label' => $label, 'modifier' => $badge['modifier'] ];
	}
}
