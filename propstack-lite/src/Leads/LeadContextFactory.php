<?php

namespace PropstackLite\Leads;

use PropstackLite\Routing\RouteResolver;
use PropstackLite\Storage\StoredProperty;

/**
 * Baut aus (bereits von CF7 verarbeiteten) Formulardaten einen verifizierten LeadContext.
 *
 * Die vom Browser gesendete Property-ID ist nur ein Hinweis: Sie wird normalisiert, im lokalen
 * Store nachgeschlagen und muss laut RouteResolver anfragbar sein (aktiv oder reserviert).
 * Titel, URL usw. kommen aus dem Store. Ohne WordPress testbar (Finder/URL-Generator injiziert).
 */
final class LeadContextFactory {

	private const MAX_NAME    = 100;
	private const MAX_PHONE   = 40;
	private const MAX_MESSAGE = 5000;

	/**
	 * @param callable(int): ?StoredProperty $finder
	 * @param callable(StoredProperty): string $urlFor kanonische URL
	 */
	public function __construct(
		private $finder,
		private RouteResolver $resolver,
		private $urlFor
	) {}

	/**
	 * @param array<string, mixed>  $posted  Rohwerte nach interner Feldbezeichnung (first_name, email, consent, …)
	 * @param mixed                 $rawPropertyId Wert des Hidden Fields psl_property_id
	 * @throws LeadException
	 */
	public function build( array $posted, mixed $rawPropertyId, string $leadId ): LeadContext {
		$id = is_scalar( $rawPropertyId ) && preg_match( '/^\s*[0-9]{1,18}\s*$/', (string) $rawPropertyId ) ? (int) $rawPropertyId : 0;
		if ( $id <= 0 ) {
			throw new LeadException( LeadException::PROPERTY_MISSING );
		}

		$stored = ( $this->finder )( $id );
		if ( null === $stored ) {
			throw new LeadException( LeadException::PROPERTY_NOT_FOUND );
		}
		$decision = $this->resolver->resolve( $stored );
		if ( ! $decision->allowsContact() || null === $stored->property ) {
			throw new LeadException( LeadException::PROPERTY_NOT_INQUIRABLE );
		}

		$data = $this->normalize( $posted );

		return new LeadContext(
			leadId: $leadId,
			property: $stored->property,
			propertyUrl: ( $this->urlFor )( $stored ),
			data: $data,
			attribution: [ 'lead_id' => $leadId ]
		);
	}

	/** @throws LeadException */
	private function normalize( array $posted ): LeadData {
		$consent = self::isAccepted( $posted['consent'] ?? null );
		if ( ! $consent ) {
			throw new LeadException( LeadException::CONSENT_MISSING );
		}

		$first = self::line( $posted['first_name'] ?? '', self::MAX_NAME );
		$last  = self::line( $posted['last_name'] ?? '', self::MAX_NAME );
		$email = self::line( $posted['email'] ?? '', 254 );
		$phone = self::line( $posted['phone'] ?? '', self::MAX_PHONE );
		$text  = self::multiline( $posted['message'] ?? '', self::MAX_MESSAGE );

		$validEmail = function_exists( 'is_email' ) ? (bool) is_email( $email ) : false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
		if ( '' === $first || '' === $last || ! $validEmail ) {
			throw new LeadException( LeadException::INVALID_INPUT );
		}

		$salutation = strtolower( self::line( $posted['salutation'] ?? '', 20 ) );
		$salutation = match ( true ) {
			in_array( $salutation, [ 'mr', 'herr' ], true ) => 'mr',
			in_array( $salutation, [ 'ms', 'frau' ], true ) => 'ms',
			default                                          => null,
		};

		return new LeadData( $salutation, $first, $last, $email, $phone, $text, true );
	}

	/** Einzeilig: Tags, Zeilenumbrüche und Steuerzeichen entfernen. */
	private static function line( mixed $value, int $max ): string {
		$value = is_array( $value ) ? implode( ' ', array_filter( $value, 'is_scalar' ) ) : $value;
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = strip_tags( (string) $value );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text ) ?? '';
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		return mb_substr( $text, 0, $max );
	}

	/** Mehrzeilig: Tags/Steuerzeichen entfernen, Zeilenumbrüche normalisieren. */
	private static function multiline( mixed $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = str_replace( [ "\r\n", "\r" ], "\n", strip_tags( (string) $value ) );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text ) ?? '';
		return mb_substr( trim( $text ), 0, $max );
	}

	/** CF7-acceptance liefert bei Zustimmung einen nicht-leeren Wert (z. B. "1"). */
	private static function isAccepted( mixed $value ): bool {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( true === $value ) {
			return true;
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		$v = strtolower( trim( (string) $value ) );
		return '' !== $v && ! in_array( $v, [ '0', 'false', 'no', 'nein', 'off' ], true );
	}
}
