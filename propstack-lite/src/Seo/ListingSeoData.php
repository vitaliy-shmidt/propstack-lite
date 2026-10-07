<?php

namespace PropstackLite\Seo;

/**
 * SEO-Werte einer Immobilienübersicht (WordPress-Seite mit `[propstack_list]`), berechnet im SeoService.
 * Title, Description und Open Graph der Seite bleiben Sache von WordPress bzw. des SEO-Plugins –
 * das Plugin ergänzt nur Canonical, Robots und den Seitenzusatz im Title.
 */
final class ListingSeoData {

	/** @param array{index: bool, follow: bool} $robots */
	public function __construct(
		public readonly string $canonical,
		public readonly array $robots,
		public readonly int $page,
		public readonly ?string $titleSuffix
	) {}

	public function isIndexable(): bool {
		return $this->robots['index'];
	}

	public function robotsString(): string {
		return ( $this->robots['index'] ? 'index' : 'noindex' ) . ', ' . ( $this->robots['follow'] ? 'follow' : 'nofollow' );
	}

	/** Title mit Seitenzusatz („… – Seite 2“) für SEO-Plugins, die nur den fertigen Title filtern. */
	public function title( string $title ): string {
		return null === $this->titleSuffix || '' === $title ? $title : $title . ' – ' . $this->titleSuffix;
	}
}
