<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Api\ApiException;
use PropstackLite\Api\Client;
use PropstackLite\Api\UnitsEndpoint;
use PropstackLite\Tests\Support\FakeTransport as T;

final class UnitsEndpointTest extends TestCase {

	private static function endpoint( T $t ): UnitsEndpoint {
		return new UnitsEndpoint( new Client( 'k', $t, static fn () => null ) );
	}

	/** Simuliert Propstack: `total_count`, Seiten à `per`, sortiert nach id. */
	private static function paginated( array $ids ): T {
		return ( new T() )->route(
			static function ( string $path, array $q ) use ( $ids ): array {
				$per   = (int) $q['per'];
				$page  = (int) $q['page'];
				$slice = array_slice( $ids, ( $page - 1 ) * $per, $per );
				return T::json(
					[
						'data' => array_map( static fn ( $id ) => [ 'id' => $id ], $slice ),
						'meta' => [ 'total_count' => count( $ids ) ],
					]
				);
			}
		);
	}

	public function test_loads_all_pages_using_total_count(): void {
		$t      = self::paginated( range( 1, 250 ) );
		$result = self::endpoint( $t )->listAll( [ 'status' => [ 5 ] ], 100 );

		$this->assertCount( 250, $result['items'] );
		$this->assertTrue( $result['complete'] );
		$this->assertSame( 250, $result['total'] );
		$this->assertCount( 3, $t->requests, 'keine überflüssige Leerseite' );

		$q = T::parseQuery( (string) parse_url( $t->requests[0]['url'], PHP_URL_QUERY ) );
		$this->assertSame( [ '5' ], $q['status'] );
		$this->assertSame( '100', $q['per'] );
		$this->assertSame( 'id', $q['sort_by'], 'stabile Sortierung gegen Duplikate' );
		$this->assertSame( 'asc', $q['order'] );
		$this->assertSame( '1', $q['expand'] );
		$this->assertSame( '1', $q['with_meta'] );
		$this->assertArrayNotHasKey( 'limit', $q, 'Propstack ignoriert limit – es wird per verwendet' );
	}

	public function test_deduplicates_ids_across_pages(): void {
		$t = ( new T() )->queue(
			T::json( [ 'data' => [ [ 'id' => 1 ], [ 'id' => 2 ] ], 'meta' => [ 'total_count' => 3 ] ] ),
			T::json( [ 'data' => [ [ 'id' => 2 ], [ 'id' => 3 ] ], 'meta' => [ 'total_count' => 3 ] ] )
		);
		$result = self::endpoint( $t )->listAll( [], 2 );
		$this->assertSame( [ 1, 2, 3 ], array_column( $result['items'], 'id' ) );
		$this->assertTrue( $result['complete'] );
	}

	public function test_incomplete_when_api_returns_fewer_than_total(): void {
		$t = ( new T() )->queue(
			T::json( [ 'data' => [ [ 'id' => 1 ] ], 'meta' => [ 'total_count' => 5 ] ] ),
			T::json( [ 'data' => [], 'meta' => [ 'total_count' => 5 ] ] )
		);
		$result = self::endpoint( $t )->listAll( [], 1 );
		$this->assertFalse( $result['complete'] );
	}

	public function test_empty_result(): void {
		$t      = ( new T() )->queue( T::json( [ 'data' => [], 'meta' => [ 'total_count' => 0 ] ] ) );
		$result = self::endpoint( $t )->listAll( [] );
		$this->assertSame( [], $result['items'] );
		$this->assertTrue( $result['complete'] );
	}

	public function test_invalid_list_shape_throws(): void {
		$this->expectException( ApiException::class );
		self::endpoint( ( new T() )->queue( T::json( [ 'errors' => [] ] ) ) )->listAll( [] );
	}

	public function test_get_returns_null_on_404(): void {
		$t = ( new T() )->queue( T::json( [ 'errors' => [ 'x' ] ], 404 ) );
		$this->assertNull( self::endpoint( $t )->get( 42 ) );
		$this->assertStringEndsWith( '/units/42?new=1', $t->requests[0]['url'] );
	}

	public function test_statuses_sorted_by_position(): void {
		$t = ( new T() )->queue( T::json( [ [ 'id' => 2, 'name' => 'B ', 'position' => 2 ], [ 'id' => 1, 'name' => 'A', 'position' => 1 ] ] ) );
		$this->assertSame( [ [ 'id' => 1, 'name' => 'A' ], [ 'id' => 2, 'name' => 'B' ] ], self::endpoint( $t )->statuses() );
	}
}
