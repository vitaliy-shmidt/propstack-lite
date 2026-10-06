<?php

namespace PropstackLite\Tracking\Consent;

/**
 * Registry der Consent-Provider. Weitere Adapter (z. B. für ein konkretes Consent-Tool) können über
 * den Filter `psl_consent_providers` ergänzt werden; unbekannte IDs fallen auf „none“ zurück.
 */
final class ConsentProviders {

	/** @return array<string, ConsentProviderInterface> */
	public static function all(): array {
		$providers = [ new NoConsentProvider(), new JsApiConsentProvider() ];
		if ( function_exists( 'apply_filters' ) ) {
			$providers = (array) apply_filters( 'psl_consent_providers', $providers );
		}
		$map = [];
		foreach ( $providers as $provider ) {
			if ( $provider instanceof ConsentProviderInterface && preg_match( '/^[a-z0-9_]{1,40}$/', $provider->id() ) ) {
				$map[ $provider->id() ] = $provider;
			}
		}
		$map['none'] ??= new NoConsentProvider();
		return $map;
	}

	public static function get( string $id ): ConsentProviderInterface {
		return self::all()[ $id ] ?? new NoConsentProvider();
	}
}
