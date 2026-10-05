<?php

namespace PropstackLite\Storage;

use PropstackLite\Domain\Property;

/** Eine Zeile aus `{prefix}psl_properties`: Zustand plus (falls noch vorhanden) das Property-Modell. */
final class StoredProperty {

	public const STATE_ACTIVE  = 'active';
	public const STATE_SOLD    = 'sold';
	public const STATE_REMOVED = 'removed';

	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $state,
		public readonly ?int $statusId,
		public readonly ?string $soldAt,
		public readonly ?string $removedAt,
		public readonly ?string $contentChangedAt,
		public readonly ?Property $property
	) {}
}
