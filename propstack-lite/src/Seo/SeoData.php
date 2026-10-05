<?php

namespace PropstackLite\Seo;

/**
 * SEO-Werte einer Detailseite (unescaped Rohwerte). Adapter geben sie aus bzw. reichen sie an
 * Yoast/Rank Math weiter – die Logik liegt ausschließlich im SeoService.
 */
final class SeoData {

	/**
	 * @param array{index: bool, follow: bool} $robots
	 * @param array<string, string>|null       $openGraph og:* ohne Präfix (title, description, url, image, image:alt, type, locale, site_name)
	 * @param array<string, string>|null       $twitter   twitter:* ohne Präfix (card, title, description, image)
	 * @param array<string, mixed>|null        $jsonLd    Schema.org-Graph (@context + @graph)
	 */
	public function __construct(
		public readonly string $title,
		public readonly ?string $description,
		public readonly ?string $canonical,
		public readonly array $robots,
		public readonly ?array $openGraph = null,
		public readonly ?array $twitter = null,
		public readonly ?array $jsonLd = null
	) {}

	public function isIndexable(): bool {
		return $this->robots['index'];
	}

	/** Robots als Text, z. B. „index, follow“. */
	public function robotsString(): string {
		return ( $this->robots['index'] ? 'index' : 'noindex' ) . ', ' . ( $this->robots['follow'] ? 'follow' : 'nofollow' );
	}

	/** @return list<array<string, mixed>> Knoten des Graphen (ohne @context) */
	public function schemaNodes(): array {
		return null === $this->jsonLd ? [] : array_values( (array) ( $this->jsonLd['@graph'] ?? [] ) );
	}
}
