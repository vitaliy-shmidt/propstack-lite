<?php
/**
 * Reproduzierbarer Release-Build: `composer build` bzw. `php bin/build-release.php [--expect=0.9.0] [--out=dist]`.
 *
 * 1. prüft Versionskonsistenz (Header „Version“ = PSL_VERSION, Requires PHP/WP = PSL_MIN_PHP/PSL_MIN_WP,
 *    Changelog-Eintrag vorhanden, optional --expect)
 * 2. sammelt Produktionsdateien per Whitelist (siehe INCLUDE) – Tests, Fixtures, vendor, Doku, Dev-Skripte,
 *    Logs, Caches, IDE-Dateien, Secrets gelangen nie ins Paket
 * 3. prüft Inhalte (PHP-Syntax, keine lokalen Pfade, kein API-Key aus Propstack-API.txt)
 * 4. erzeugt dist/propstack-lite-{version}.zip (Ordner propstack-lite/), deterministisch: sortierte Einträge,
 *    feste Zeitstempel (SOURCE_DATE_EPOCH bzw. Datum des Changelog-Eintrags), LF-Zeilenenden, feste Rechte
 * 5. schreibt .sha256 und eine Dateiliste
 *
 * Läuft ohne Abhängigkeiten (PHP ≥ 8.1 mit ext-zip). Doku: docs/release-1.0.md („Release-Build“).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$opts = getopt( '', [ 'expect:', 'out:' ] );
$out  = isset( $opts['out'] ) ? rtrim( (string) $opts['out'], '/\\' ) : $root . '/dist';

/** Ins Paket: Dateien bzw. Verzeichnisse mit erlaubten Endungen. */
const INCLUDE_FILES = [ 'propstack-lite.php', 'uninstall.php' ];
const INCLUDE_DIRS  = [
	'src'         => [ 'php' ],
	'templates'   => [ 'php' ],
	'assets/css'  => [ 'css' ],
	'assets/js'   => [ 'js' ],
];

$fail = static function ( string $msg ): never {
	fwrite( STDERR, "FEHLER: {$msg}\n" );
	exit( 1 );
};
$info = static function ( string $msg ): void {
	fwrite( STDOUT, $msg . "\n" );
};

if ( ! class_exists( ZipArchive::class ) ) {
	$fail( 'ext-zip fehlt.' );
}

/* ------------------------------------------------------------------ Version */

$main = (string) file_get_contents( $root . '/propstack-lite.php' );
$hdr  = static fn ( string $field ): ?string => preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(\S+)/m', $main, $m ) ? $m[1] : null;
$def  = static fn ( string $const ): ?string => preg_match( "/define\(\s*'" . $const . "',\s*'([^']+)'\s*\)/", $main, $m ) ? $m[1] : null;

$version = $hdr( 'Version' );
if ( null === $version || $version !== $def( 'PSL_VERSION' ) ) {
	$fail( sprintf( 'Header-Version (%s) und PSL_VERSION (%s) stimmen nicht überein.', $version ?? '–', $def( 'PSL_VERSION' ) ?? '–' ) );
}
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	$fail( "Version {$version} ist keine SemVer-Version x.y.z (WordPress vergleicht per version_compare)." );
}
if ( $hdr( 'Requires PHP' ) !== $def( 'PSL_MIN_PHP' ) || $hdr( 'Requires at least' ) !== $def( 'PSL_MIN_WP' ) ) {
	$fail( 'Requires PHP / Requires at least im Header weichen von PSL_MIN_PHP / PSL_MIN_WP ab.' );
}
if ( isset( $opts['expect'] ) && $opts['expect'] !== $version ) {
	$fail( "Erwartet {$opts['expect']}, Plugin-Version ist {$version}." );
}
$changelog = dirname( $root ) . '/docs/changelog.md';
if ( is_readable( $changelog ) && ! preg_match( '/^## .*\(Version ' . preg_quote( $version, '/' ) . '\)/m', (string) file_get_contents( $changelog ) ) ) {
	$fail( "docs/changelog.md enthält keinen Eintrag „(Version {$version})“." );
}
$info( "Version {$version} konsistent (Header, PSL_VERSION, Changelog)." );

/* ------------------------------------------------- Runtime-Abhängigkeiten */

// Das Plugin hat keine Composer-Laufzeitabhängigkeiten (eigener Autoloader) – vendor/ gehört nie ins Paket.
// Käme eine hinzu, müsste der Build sie per `composer install --no-dev --optimize-autoloader` in einem
// separaten Verzeichnis einbinden; bis dahin bricht er ab, statt ein unvollständiges Paket zu bauen.
$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
$runtime  = array_filter( array_keys( (array) ( $composer['require'] ?? [] ) ), static fn ( $p ) => 'php' !== $p && ! str_starts_with( $p, 'ext-' ) );
if ( [] !== $runtime ) {
	$fail( 'composer.json enthält Laufzeitabhängigkeiten (' . implode( ', ', $runtime ) . ') – Build-Weg mit --no-dev-vendor erforderlich (docs/release-1.0.md).' );
}
$info( 'Keine Composer-Laufzeitabhängigkeiten – vendor/ wird nicht ausgeliefert.' );

/* ------------------------------------------------------------------ Dateien */

$files = [];
foreach ( INCLUDE_FILES as $file ) {
	$files[] = $file;
}
foreach ( INCLUDE_DIRS as $dir => $extensions ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		/** @var SplFileInfo $f */
		if ( $f->isFile() && in_array( strtolower( $f->getExtension() ), $extensions, true ) ) {
			$files[] = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $root ) + 1 ) );
		}
	}
}
sort( $files, SORT_STRING );

/* ------------------------------------------------------------------ Prüfungen */

$secret = null;
$keyFile = dirname( $root ) . '/Propstack-API.txt';
if ( is_readable( $keyFile ) && preg_match( '/[A-Za-z0-9_\-]{20,}/', (string) file_get_contents( $keyFile ), $m ) ) {
	$secret = $m[0]; // nur zum Vergleich, wird nie ausgegeben
}
$contents = [];
foreach ( $files as $file ) {
	$data = (string) file_get_contents( $root . '/' . $file );
	$data = str_replace( "\r\n", "\n", $data ); // plattformunabhängig (Git autocrlf unter Windows)
	if ( null !== $secret && str_contains( $data, $secret ) ) {
		$fail( "{$file} enthält den Propstack-API-Key." );
	}
	if ( preg_match( '#[A-Za-z]:\\\\(Users|xampp)|/Users/[A-Za-z]|/home/[a-z]+/|xampp[/\\\\]htdocs|127\.0\.0\.1:8099#i', $data, $m ) ) {
		$fail( "{$file} enthält einen lokalen Pfad/Host ({$m[0]})." );
	}
	if ( str_ends_with( $file, '.php' ) ) {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $root . '/' . $file ) . ' 2>&1';
		exec( $cmd, $lint, $code );
		if ( 0 !== $code ) {
			$fail( "PHP-Syntaxfehler in {$file}: " . implode( ' ', $lint ) );
		}
	}
	$contents[ $file ] = $data;
}
// Kein Dev-/Test-/Lokalpfad im Paket (Defense in Depth zur Whitelist).
foreach ( $files as $file ) {
	if ( preg_match( '#(^|/)(vendor|node_modules|tests?|fixtures|dist|bin|docs|\.git|\.github|\.idea|\.vscode|\.phpunit\.cache)(/|$)|phpstan|phpunit|brain|wordpress-stubs|phpcs|\.(log|sql|zip|png|jpe?g|txt|md|neon|xml|dist|lock|json)$#i', $file ) ) {
		$fail( "Unzulässige Datei im Paket: {$file}" );
	}
}
$unpacked = array_sum( array_map( 'strlen', $contents ) );
$info( count( $files ) . ' Dateien geprüft (Syntax, lokale Pfade, Secrets, keine Dev-/Test-Dateien).' );

/* ------------------------------------------------------------------ ZIP */

// Zeitstempel aller Einträge: SOURCE_DATE_EPOCH, sonst Datum des Changelog-Eintrags der Version (00:00 UTC) –
// unabhängig von späteren Doku-Commits, damit dasselbe Paket bitgenau neu gebaut werden kann.
$epoch = getenv( 'SOURCE_DATE_EPOCH' );
if ( ( false === $epoch || ! ctype_digit( $epoch ) ) && is_readable( $changelog ) && preg_match( '/^## (\d{4}-\d{2}-\d{2}).*\(Version ' . preg_quote( $version, '/' ) . '\)/m', (string) file_get_contents( $changelog ), $m ) ) {
	$epoch = (string) strtotime( $m[1] . ' 00:00:00 UTC' );
}
$mtime = ctype_digit( (string) $epoch ) ? (int) $epoch : 1767225600; // Fallback 2026-01-01

if ( ! is_dir( $out ) && ! mkdir( $out, 0775, true ) ) {
	$fail( "Ausgabeverzeichnis {$out} nicht anlegbar." );
}
$zipPath = $out . '/propstack-lite-' . $version . '.zip';
@unlink( $zipPath );

$zip = new ZipArchive();
if ( true !== $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	$fail( "ZIP {$zipPath} nicht anlegbar." );
}
$dirs = [ 'propstack-lite/' => true ];
foreach ( $files as $file ) {
	$parts = explode( '/', $file );
	array_pop( $parts );
	$path = 'propstack-lite/';
	foreach ( $parts as $part ) {
		$path          .= $part . '/';
		$dirs[ $path ] = true;
	}
}
ksort( $dirs, SORT_STRING );
foreach ( array_keys( $dirs ) as $dir ) {
	$zip->addEmptyDir( rtrim( $dir, '/' ) );
	$zip->setMtimeName( $dir, $mtime );
	$zip->setExternalAttributesName( $dir, ZipArchive::OPSYS_UNIX, ( 040755 << 16 ) );
}
foreach ( $files as $file ) {
	$name = 'propstack-lite/' . $file;
	$zip->addFromString( $name, $contents[ $file ] );
	$zip->setMtimeName( $name, $mtime );
	$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, ( 0100644 << 16 ) );
	$zip->setCompressionName( $name, ZipArchive::CM_DEFLATE );
}
if ( ! $zip->close() ) {
	$fail( 'ZIP konnte nicht geschrieben werden.' );
}

$hash = hash_file( 'sha256', $zipPath );
file_put_contents( $zipPath . '.sha256', $hash . '  ' . basename( $zipPath ) . "\n" );
file_put_contents( $out . '/propstack-lite-' . $version . '.files.txt', implode( "\n", array_map( static fn ( $f ) => 'propstack-lite/' . $f, $files ) ) . "\n" );

$info( sprintf( 'Erstellt: %s (ZIP %s KB, entpackt %s KB, %d Dateien, Zeitstempel %s UTC)', $zipPath, number_format( filesize( $zipPath ) / 1024, 1, ',', '.' ), number_format( $unpacked / 1024, 1, ',', '.' ), count( $files ), gmdate( 'Y-m-d H:i:s', $mtime ) ) );
$info( 'SHA256: ' . $hash );
