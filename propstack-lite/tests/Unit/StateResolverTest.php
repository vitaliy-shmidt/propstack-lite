<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PropstackLite\Sync\StateResolver as R;

final class StateResolverTest extends TestCase {

	private const PUBLIC = 10;
	private const SOLD   = 20;
	private const OTHER  = 30;

	public static function cases(): array {
		return [
			// statusId, archived, deleted, storedState, expected
			'neu & öffentlich'                   => [ self::PUBLIC, false, false, null, R::UPSERT ],
			'aktiv & öffentlich'                 => [ self::PUBLIC, false, false, 'active', R::UPSERT ],
			'öffentlich aber archiviert, neu'    => [ self::PUBLIC, true, false, null, R::IGNORE ],
			'öffentlich aber archiviert, aktiv'  => [ self::PUBLIC, true, false, 'active', R::REMOVE ],
			'verkauft, zuvor aktiv'              => [ self::SOLD, false, false, 'active', R::SOLD ],
			'verkauft & archiviert, zuvor aktiv' => [ self::SOLD, true, false, 'active', R::SOLD ],
			'verkauft, nie öffentlich'           => [ self::SOLD, false, false, null, R::IGNORE ],
			'verkauft, bereits entfernt'         => [ self::SOLD, false, false, 'removed', R::IGNORE ],
			'verkauft → anderer Status'          => [ self::OTHER, false, false, 'sold', R::KEEP ],
			'verkauft → archiviert'              => [ self::SOLD, true, false, 'sold', R::KEEP ],
			'verkauft → wieder öffentlich'       => [ self::PUBLIC, false, false, 'sold', R::UPSERT ],
			'entfernt → wieder öffentlich'       => [ self::PUBLIC, false, false, 'removed', R::UPSERT ],
			'anderer Status, zuvor aktiv'        => [ self::OTHER, false, false, 'active', R::REMOVE ],
			'anderer Status, nie öffentlich'     => [ self::OTHER, false, false, null, R::IGNORE ],
			'ohne Status, zuvor aktiv'           => [ null, false, false, 'active', R::REMOVE ],
			'gelöscht, zuvor aktiv'              => [ null, false, true, 'active', R::REMOVE ],
			'gelöscht, zuvor verkauft'           => [ null, false, true, 'sold', R::REMOVE ],
			'gelöscht, nie gespeichert'          => [ null, false, true, null, R::IGNORE ],
		];
	}

	#[DataProvider( 'cases' )]
	public function test_resolve( ?int $statusId, bool $archived, bool $deleted, ?string $stored, string $expected ): void {
		$resolver = new R( [ self::PUBLIC ], [ self::SOLD ] );
		$this->assertSame( $expected, $resolver->resolve( $statusId, $archived, $deleted, $stored ) );
	}

	public function test_public_wins_if_status_is_in_both_lists(): void {
		$resolver = new R( [ self::PUBLIC ], [ self::PUBLIC ] );
		$this->assertSame( R::UPSERT, $resolver->resolve( self::PUBLIC, false, false, 'active' ) );
	}
}
