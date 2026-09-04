<?php
/**
 * Regenerate languages/myotp-phone-verification.pot from the sources.
 * Scans every PHP file in the plugin for __(), _e(), esc_html__(),
 * esc_html_e(), esc_attr__(), esc_attr_e() with the plugin text domain and
 * writes one msgid per distinct string, with translator comments when the
 * preceding line carries one.
 *
 * Run inside the php:8.2-cli container from wordpress-plugin/:
 *   php bin/make-pot.php
 */

declare(strict_types=1);

$root   = dirname( __DIR__ ) . '/myotp-phone-verification';
$domain = 'myotp-phone-verification';
$out    = $root . '/languages/' . $domain . '.pot';

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$found = array(); // msgid => comment|null

foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$lines = file( $file->getPathname() );
	foreach ( $lines as $i => $line ) {
		if ( ! preg_match_all( "/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'" . preg_quote( $domain, '/' ) . "'/", $line, $m ) ) {
			continue;
		}
		$comment = null;
		if ( $i > 0 && preg_match( '#/\*\s*translators:\s*(.*?)\s*\*/#', $lines[ $i - 1 ], $c ) ) {
			$comment = $c[1];
		}
		foreach ( $m[1] as $raw ) {
			$msgid = str_replace( "\\'", "'", $raw );
			if ( ! isset( $found[ $msgid ] ) || null === $found[ $msgid ] ) {
				$found[ $msgid ] = $comment;
			}
		}
	}
}
ksort( $found, SORT_STRING );

$esc = function ( string $s ): string {
	return str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $s );
};

$pot  = "# Copyright (C) 2026 MyOTP.App\n";
$pot .= "# This file is distributed under the GPL-2.0-or-later.\n";
$pot .= "msgid \"\"\nmsgstr \"\"\n";
$pot .= "\"Project-Id-Version: MyOTP Phone Verification 1.0.0\\n\"\n";
$pot .= "\"Report-Msgid-Bugs-To: https://github.com/brntech/myotp-agentkit/issues\\n\"\n";
$pot .= "\"POT-Creation-Date: " . gmdate( 'Y-m-d H:i' ) . "+0000\\n\"\n";
$pot .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
$pot .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n\"Last-Translator: \\n\"\n\"Language-Team: \\n\"\n\"Language: \\n\"\n";
$pot .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
$pot .= "\"X-Generator: bin/make-pot.php\\n\"\n\"X-Domain: $domain\\n\"\n\n";

foreach ( $found as $msgid => $comment ) {
	if ( null !== $comment ) {
		$pot .= "#. translators: $comment\n";
	}
	$pot .= 'msgid "' . $esc( $msgid ) . "\"\nmsgstr \"\"\n\n";
}

file_put_contents( $out, $pot );
echo count( $found ) . " strings written to $out\n";
