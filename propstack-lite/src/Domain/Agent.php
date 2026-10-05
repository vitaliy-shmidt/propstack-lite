<?php

namespace PropstackLite\Domain;

/**
 * Ansprechpartner (Propstack-Makler) – ausschließlich öffentliche Felder.
 *
 * `email`, `phone` und `cell` stammen aus `public_email`, `public_phone` und `public_cell`;
 * die internen Broker-Kontaktdaten werden nie übernommen.
 */
final class Agent {

	public function __construct(
		public readonly ?string $name,
		public readonly ?string $firstName,
		public readonly ?string $lastName,
		public readonly ?string $academicTitle,
		public readonly ?string $salutation,
		public readonly ?string $position,
		public readonly ?string $avatarUrl,
		public readonly ?string $email,
		public readonly ?string $phone,
		public readonly ?string $cell
	) {}

	public function displayName(): ?string {
		if ( null !== $this->name ) {
			return $this->name;
		}
		$name = trim( implode( ' ', array_filter( [ $this->academicTitle, $this->firstName, $this->lastName ] ) ) );
		return '' === $name ? null : $name;
	}

	public function hasContactChannel(): bool {
		return null !== $this->email || null !== $this->phone || null !== $this->cell;
	}

	public function toArray(): array {
		return [
			'name'          => $this->name,
			'firstName'     => $this->firstName,
			'lastName'      => $this->lastName,
			'academicTitle' => $this->academicTitle,
			'salutation'    => $this->salutation,
			'position'      => $this->position,
			'avatarUrl'     => $this->avatarUrl,
			'email'         => $this->email,
			'phone'         => $this->phone,
			'cell'          => $this->cell,
		];
	}

	public static function fromArray( array $a ): self {
		return new self(
			$a['name'] ?? null,
			$a['firstName'] ?? null,
			$a['lastName'] ?? null,
			$a['academicTitle'] ?? null,
			$a['salutation'] ?? null,
			$a['position'] ?? null,
			$a['avatarUrl'] ?? null,
			$a['email'] ?? null,
			$a['phone'] ?? null,
			$a['cell'] ?? null
		);
	}
}
