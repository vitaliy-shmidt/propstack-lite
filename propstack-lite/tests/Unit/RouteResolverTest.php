<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Domain\Property;
use PropstackLite\Frontend\PropertyViewModel;
use PropstackLite\Routing\RouteDecision;
use PropstackLite\Routing\RouteResolver;
use PropstackLite\Storage\StoredProperty;

final class RouteResolverTest extends TestCase {

	private const PUBLIC   = 10;
	private const RESERVED = 11;
	private const OTHER    = 99;

	private static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2026-10-05 12:00:00', new \DateTimeZone( 'UTC' ) );
	}

	private static function resolver(): RouteResolver {
		return new RouteResolver( [ self::PUBLIC, self::RESERVED ], [ self::RESERVED ], self::now() );
	}

	private static function property( string $marketing = 'BUY' ): Property {
		return Property::fromArray( [ 'id' => 1, 'slug' => 'wohnung-kaufen-berlin', 'marketingType' => $marketing, 'address' => [ 'hidden' => true ] ] );
	}

	private static function stored( string $state, ?int $status, bool $withData = true, ?string $soldAt = null, string $marketing = 'BUY' ): StoredProperty {
		return new StoredProperty( 1, 'wohnung-kaufen-berlin', $state, $status, $soldAt, null, null, $withData ? self::property( $marketing ) : null );
	}

	public function test_unknown_id_is_404(): void {
		$d = self::resolver()->resolve( null );
		$this->assertSame( 404, $d->httpStatus );
		$this->assertSame( RouteDecision::NOT_FOUND, $d->state );
		$this->assertFalse( $d->isRenderable() );
	}

	public function test_active_public_is_200_indexable_with_contact(): void {
		$d = self::resolver()->resolve( self::stored( 'active', self::PUBLIC ) );
		$this->assertSame( 200, $d->httpStatus );
		$this->assertSame( RouteDecision::ACTIVE, $d->state );
		$this->assertFalse( $d->isNoindex() );
		$this->assertTrue( $d->allowsContact() );
	}

	public function test_reserved_is_200_indexable_with_contact(): void {
		$d = self::resolver()->resolve( self::stored( 'active', self::RESERVED ) );
		$this->assertSame( [ 200, RouteDecision::RESERVED ], [ $d->httpStatus, $d->state ] );
		$this->assertFalse( $d->isNoindex() );
		$this->assertTrue( $d->allowsContact() );
	}

	public function test_active_but_status_no_longer_public_is_410(): void {
		$d = self::resolver()->resolve( self::stored( 'active', self::OTHER ) );
		$this->assertSame( [ 410, RouteDecision::GONE ], [ $d->httpStatus, $d->state ] );
	}

	public function test_active_without_data_is_410(): void {
		$this->assertSame( 410, self::resolver()->resolve( self::stored( 'active', self::PUBLIC, false ) )->httpStatus );
	}

	public function test_sold_within_30_days_is_200_noindex_without_contact(): void {
		$d = self::resolver()->resolve( self::stored( 'sold', 50, true, '2026-09-10 12:00:00' ) );
		$this->assertSame( [ 200, RouteDecision::SOLD ], [ $d->httpStatus, $d->state ] );
		$this->assertTrue( $d->isNoindex() );
		$this->assertFalse( $d->allowsContact() );
	}

	public function test_sold_window_boundary(): void {
		$this->assertSame( 200, self::resolver()->resolve( self::stored( 'sold', 50, true, '2026-09-05 12:00:01' ) )->httpStatus, '29 Tage 23:59:59' );
		$this->assertSame( 410, self::resolver()->resolve( self::stored( 'sold', 50, true, '2026-09-05 12:00:00' ) )->httpStatus, 'genau 30 Tage' );
		$this->assertSame( 410, self::resolver()->resolve( self::stored( 'sold', 50, true, '2026-08-01 00:00:00' ) )->httpStatus );
	}

	public function test_sold_without_data_or_date_is_410(): void {
		$this->assertSame( 410, self::resolver()->resolve( self::stored( 'sold', 50, false, '2026-10-01 00:00:00' ) )->httpStatus );
		$this->assertSame( 410, self::resolver()->resolve( self::stored( 'sold', 50, true, null ) )->httpStatus );
	}

	public function test_removed_is_410_noindex(): void {
		$d = self::resolver()->resolve( self::stored( 'removed', null, false ) );
		$this->assertSame( 410, $d->httpStatus );
		$this->assertTrue( $d->isNoindex() );
	}

	public function test_status_badges(): void {
		$sold = new RouteDecision( RouteDecision::SOLD, 200 );
		$this->assertSame( [ 'label' => 'Verkauft', 'modifier' => 'sold' ], PropertyViewModel::statusBadge( self::property( 'BUY' ), $sold ) );
		$this->assertSame( [ 'label' => 'Vermietet', 'modifier' => 'sold' ], PropertyViewModel::statusBadge( self::property( 'RENT' ), $sold ) );
		$this->assertSame( 'Reserviert', PropertyViewModel::statusBadge( self::property(), new RouteDecision( RouteDecision::RESERVED, 200 ) )['label'] );
		$this->assertNull( PropertyViewModel::statusBadge( self::property(), new RouteDecision( RouteDecision::ACTIVE, 200 ) ) );
	}
}
