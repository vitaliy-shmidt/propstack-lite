<?php

namespace PropstackLite\Domain;

/**
 * Öffentlich freigegebenes Bild (private und nicht fürs Exposé freigegebene Bilder
 * erreichen dieses Modell nie). URLs zeigen ausschließlich auf *.propstack.de (HTTPS).
 */
final class Image {

	public function __construct(
		public readonly ?int $id,
		public readonly ?string $title,
		public readonly ?int $position,
		public readonly bool $isFloorplan,
		public readonly ?string $url,
		public readonly ?string $big,
		public readonly ?string $medium,
		public readonly ?string $thumb,
		public readonly ?string $square,
		public readonly ?string $smallThumb
	) {}

	/** Beste verfügbare URL für die gewünschte Größe, mit Fallback auf andere Größen. */
	public function src( string $size = 'medium' ): ?string {
		$chains = [
			'thumb'  => [ $this->thumb, $this->medium, $this->big, $this->url ],
			'medium' => [ $this->medium, $this->big, $this->url, $this->thumb ],
			'big'    => [ $this->big, $this->url, $this->medium ],
			'full'   => [ $this->url, $this->big, $this->medium ],
		];
		foreach ( $chains[ $size ] ?? $chains['medium'] as $candidate ) {
			if ( null !== $candidate ) {
				return $candidate;
			}
		}
		return null;
	}

	public function toArray(): array {
		return [
			'id'          => $this->id,
			'title'       => $this->title,
			'position'    => $this->position,
			'isFloorplan' => $this->isFloorplan,
			'url'         => $this->url,
			'big'         => $this->big,
			'medium'      => $this->medium,
			'thumb'       => $this->thumb,
			'square'      => $this->square,
			'smallThumb'  => $this->smallThumb,
		];
	}

	public static function fromArray( array $a ): self {
		return new self(
			isset( $a['id'] ) ? (int) $a['id'] : null,
			$a['title'] ?? null,
			isset( $a['position'] ) ? (int) $a['position'] : null,
			(bool) ( $a['isFloorplan'] ?? false ),
			$a['url'] ?? null,
			$a['big'] ?? null,
			$a['medium'] ?? null,
			$a['thumb'] ?? null,
			$a['square'] ?? null,
			$a['smallThumb'] ?? null
		);
	}
}
