<?php
/**
 * Design-Upgrade für die öffentliche Website (ab 1.5.0).
 *
 * - Lädt assets/vlj-design.css und assets/vlj-design.js auf allen öffentlichen Seiten
 *   (nicht im Brizy-Editor, nicht im Adminbereich).
 * - Bereitet die fertige Seite nach: passende Bildgrößen (srcset statt Originaldateien),
 *   leere Alternativtexte aus der Mediathek, nur eine Hauptüberschrift (h1) je Seite,
 *   saubere Seitenbeschreibung (meta description) aus dem vorhandenen Seitentext.
 * - Ein- und ausschalten: Option vljcr_design (1/0), über text_ersetzen mit suchen "@design"
 *   und ersetzen "an" oder "aus". E-Mail-Adressen der Vorstandskarten: Option vljcr_vorstand_mails
 *   (Name => Adresse), über suchen "@design_mails" und ersetzen JSON.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function vljcr_design_aktiv() {
	return '0' !== (string) get_option( 'vljcr_design', '1' );
}

function vljcr_design_mails() {
	$m = get_option( 'vljcr_vorstand_mails', null );
	if ( ! is_array( $m ) ) {
		$m = array(
			'Prof. Dr. Stefan Birkner' => 'stefan.birkner@drbirkner.com',
			'Henning Schröder'         => 'h.schroeder@rakanzlei-hs.de',
			'Dr. Frank Rozanski'       => 'anwalt@dr-rozanski.de',
			'Dr. Johannes Kevekordes'  => 'dr.kevekordes@kevekordes-partner.de',
			'Joris Stietenroth'        => 'joris.stietenroth@t-online.de',
		);
	}
	return $m;
}

function vljcr_design_editor() {
	foreach ( array( 'brizy-edit', 'brizy-edit-iframe', 'brizy_post', 'customize_changeset_uuid', 'elementor-preview' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
	}
	return false;
}

function vljcr_design_seite() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || vljcr_design_editor() || is_feed() || is_embed() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}
	return vljcr_design_aktiv();
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! vljcr_design_seite() ) {
			return;
		}
		$url = plugin_dir_url( __FILE__ ) . 'assets/';
		$dir = plugin_dir_path( __FILE__ ) . 'assets/';
		$v   = VLJCR_VERSION . '-' . ( file_exists( $dir . 'vlj-design.js' ) ? filemtime( $dir . 'vlj-design.js' ) : '0' );
		wp_enqueue_style( 'vljcr-design', $url . 'vlj-design.css', array(), $v );
		wp_enqueue_script( 'vljcr-design', $url . 'vlj-design.js', array(), $v, true );
		$m = array();
		foreach ( vljcr_design_mails() as $name => $adr ) {
			if ( is_email( $adr ) ) {
				$m[ (string) $name ] = base64_encode( strrev( (string) $adr ) );
			}
		}
		$weiss  = wp_get_attachment_url( 430 );
		$vorsitz = wp_get_attachment_image_src( 704, 'medium' );
		$cfg     = array(
			'm'         => (object) $m,
			'logoWeiss' => $weiss ? $weiss : '',
			'bilder'    => array( 'vorsitz' => $vorsitz ? $vorsitz[0] : '' ),
			'profile'   => (object) vljcr_design_profile(),
		);
		wp_add_inline_script( 'vljcr-design', 'window.VLJ_CFG=' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	},
	99
);

// Kurzbiografien, LinkedIn und Gesichtsmitte (gx: waagerechte Lage im Foto, 0–1) der Vorstandsmitglieder (Option vljcr_vorstand_profile, sonst diese Voreinstellung).
function vljcr_design_profile() {
	$p = get_option( 'vljcr_vorstand_profile', null );
	if ( is_array( $p ) ) {
		return $p;
	}
	return array(
		'Prof. Dr. Stefan Birkner' => array(
			'foto'     => array( 'x' => '4.5%', 's' => 1.1 ),
			'linkedin' => 'https://www.linkedin.com/in/stefan-birkner-978373111',
			'bio'      => 'Stefan Birkner studierte Rechtswissenschaften in Hannover und wurde an der Europa-Universität Viadrina in Frankfurt (Oder) promoviert. Er war Staatsanwalt und Richter, später Staatssekretär und von 2012 bis 2013 Niedersächsischer Minister für Umwelt, Energie und Klimaschutz. Dem Niedersächsischen Landtag gehörte er bis 2022 an.',
		),
		'Thomas Pfleiderer'        => array( 'gx' => 0.54 ),
		'Dr. Frederike Alt'        => array( 'gx' => 0.479 ),
		'Henning Schröder'         => array( 'gx' => 0.55, 'linkedin' => 'https://www.linkedin.com/in/henning-schr%C3%B6der-84aa7b1' ),
		'Dr. Frank Rozanski'       => array( 'gx' => 0.552, 'linkedin' => 'https://www.linkedin.com/in/dr-frank-rozanski' ),
		'Dr. Johannes Kevekordes'  => array( 'gx' => 0.584, 'linkedin' => 'https://www.linkedin.com/in/johannes-kevekordes-4814b488' ),
		'Niklas Drexler'           => array( 'gx' => 0.526, 'linkedin' => 'https://www.linkedin.com/in/niklas-drexler-442012170' ),
		'Joris Stietenroth'        => array(
			'gx'       => 0.498,
			'linkedin' => 'https://www.linkedin.com/in/joris-stietenroth',
			'bio'      => 'Joris Stietenroth studiert seit 2023 Rechtswissenschaft an der Universität Hamburg. Er arbeitet in einer Steuerberatungskanzlei und am Lehrstuhl für Europa- und Völkerrecht. Seit 2025 ist er Landesvorsitzender der Jungen Liberalen Niedersachsen.',
		),
	);
}

// Früh im Kopf: Seite bis zum Aufbau verbergen, Sicherheitsnetz nach 2,5 Sekunden.
add_action(
	'wp_head',
	function () {
		if ( ! vljcr_design_seite() ) {
			return;
		}
		$f = plugin_dir_url( __FILE__ ) . 'assets/fonts/';
		echo '<link rel="preload" href="' . esc_url( $f . 'roboto-latin-400-normal.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
		echo '<link rel="preload" href="' . esc_url( $f . 'roboto-latin-700-normal.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
		echo "<script>document.documentElement.classList.add('vlj-ui');setTimeout(function(){var h=document.documentElement;if(!h.classList.contains('vlj-fertig'))h.classList.remove('vlj-ui')},2500);</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	},
	1
);

// Versionsangabe an den eigenen Dateien erhalten (die Website entfernt ?ver= sonst), damit Browser neue Stände laden.
function vljcr_design_version() {
	$f = plugin_dir_path( __FILE__ ) . 'assets/vlj-design.js';
	$c = plugin_dir_path( __FILE__ ) . 'assets/vlj-design.css';
	return substr( md5( VLJCR_VERSION . ( file_exists( $f ) ? filemtime( $f ) : 0 ) . ( file_exists( $c ) ? filemtime( $c ) : 0 ) ), 0, 10 );
}
foreach ( array( 'style_loader_src', 'script_loader_src' ) as $vljcr_filter ) {
	add_filter(
		$vljcr_filter,
		function ( $src, $handle ) {
			if ( 'vljcr-design' === $handle && false === strpos( (string) $src, 'vv=' ) ) {
				$src = add_query_arg( 'vv', vljcr_design_version(), $src );
			}
			return $src;
		},
		PHP_INT_MAX,
		2
	);
}

/* ---------- Website-Symbol (Favicon, Google, Startbildschirm) ---------- */

// Eigenes Symbol: weißes VLJ-Zeichen auf Logo-Blau, randlos gefüllt; bleibt auch im Kreisausschnitt von Google vollständig.
function vljcr_icon_url( $groesse ) {
	$verfuegbar = array( 32, 48, 96, 144, 180, 192, 270, 512 );
	$wahl       = 512;
	foreach ( $verfuegbar as $g ) {
		if ( $g >= (int) $groesse ) {
			$wahl = $g;
			break;
		}
	}
	return plugin_dir_url( __FILE__ ) . 'assets/icon/vlj-icon-' . $wahl . '.png?v=' . rawurlencode( VLJCR_VERSION );
}
add_filter(
	'get_site_icon_url',
	function ( $url, $size ) {
		return vljcr_design_aktiv() ? vljcr_icon_url( $size ) : $url;
	},
	20,
	2
);
add_filter(
	'site_icon_meta_tags',
	function ( $tags ) {
		if ( ! vljcr_design_aktiv() ) {
			return $tags;
		}
		$d = plugin_dir_url( __FILE__ ) . 'assets/icon/';
		$v = '?v=' . rawurlencode( VLJCR_VERSION );
		return array(
			'<link rel="icon" href="' . esc_url( $d . 'favicon.ico' . $v ) . '" sizes="48x48" />',
			'<link rel="icon" href="' . esc_url( $d . 'vlj-icon.svg' . $v ) . '" type="image/svg+xml" />',
			'<link rel="icon" href="' . esc_url( vljcr_icon_url( 192 ) ) . '" sizes="192x192" type="image/png" />',
			'<link rel="apple-touch-icon" href="' . esc_url( vljcr_icon_url( 180 ) ) . '" />',
			'<meta name="msapplication-TileImage" content="' . esc_url( vljcr_icon_url( 270 ) ) . '" />',
			'<meta name="theme-color" content="#014e84" />',
		);
	},
	20
);

/* ---------- Nachbearbeitung der fertigen Seite ---------- */

add_action(
	'template_redirect',
	function () {
		if ( vljcr_design_seite() && ! is_404() ) {
			ob_start( 'vljcr_design_puffer' );
		}
	},
	1
);

function vljcr_design_bild_id( $url ) {
	static $cache = null;
	if ( null === $cache ) {
		$cache = get_transient( 'vljcr_bild_ids' );
		$cache = is_array( $cache ) ? $cache : array();
	}
	$schluessel = md5( $url );
	if ( ! array_key_exists( $schluessel, $cache ) ) {
		$id = attachment_url_to_postid( $url );
		if ( ! $id && preg_match( '/-scaled\.(jpe?g|png|webp)$/i', $url ) ) {
			$id = attachment_url_to_postid( preg_replace( '/-scaled(\.[a-z]+)$/i', '$1', $url ) );
		}
		$cache[ $schluessel ] = (int) $id;
		set_transient( 'vljcr_bild_ids', $cache, WEEK_IN_SECONDS );
	}
	return $cache[ $schluessel ];
}

function vljcr_design_puffer( $html ) {
	if ( ! is_string( $html ) || strlen( $html ) < 200 || false === stripos( $html, '<html' ) ) {
		return $html;
	}
	try {
		$uploads = wp_get_upload_dir();
		$basis   = preg_quote( set_url_scheme( $uploads['baseurl'], 'https' ), '~' );
		$basis   = str_replace( 'https\\:', 'https?\\:', $basis );

		// Klassen der Spalten (Vorstandskarten u. ä.) – deren Hintergrundbilder brauchen keine 1536 px.
		$spalten = array();
		if ( preg_match_all( '~class="brz-columns ([^"]*)"~', $html, $mm ) ) {
			foreach ( $mm[1] as $kl ) {
				foreach ( preg_split( '/\s+/', $kl ) as $k ) {
					$spalten[ $k ] = true;
				}
			}
		}

		// 0. Hinweis „Managed with … by Leo Skull“ in der Fußzeile entfernen (abgeklärt, Nov. 2026).
		$html = preg_replace( '~<p\b[^>]*>(?:(?!</p>).)*leo-skull(?:(?!</p>).)*</p>~is', '', $html );

		// 1. Hintergrundbilder in den Stilregeln auf eine passende Größe umstellen.
		$html = preg_replace_callback(
			'~(\.brz \.(brz-css-[a-z0-9]+)[^{}]*\{[^{}]*?)url\(\s*(["\']?)(' . $basis . '/[^"\')]+\.(?:jpe?g|png|webp))\3\s*\)~i',
			function ( $m ) use ( $spalten ) {
				$id = vljcr_design_bild_id( $m[4] );
				if ( ! $id ) {
					return $m[0];
				}
				$groesse = isset( $spalten[ $m[2] ] ) ? '1536x1536' : '2048x2048';
				$src     = wp_get_attachment_image_src( $id, $groesse );
				if ( ! $src || empty( $src[0] ) ) {
					return $m[0];
				}
				if ( isset( $spalten[ $m[2] ] ) ) {
					$gross = wp_get_attachment_image_src( $id, '2048x2048' );
					if ( $gross && ! empty( $gross[0] ) ) {
						$GLOBALS['vljcr_gross'][ $src[0] ] = $gross[0];
					}
				}
				return $m[1] . 'url(' . $m[3] . $src[0] . $m[3] . ')';
			},
			$html
		);

		// 2. Bilder: srcset/sizes ergänzen, leere Alternativtexte aus der Mediathek füllen.
		$html = preg_replace_callback(
			'~<img\b[^>]*>~i',
			function ( $m ) use ( $basis ) {
				$tag = $m[0];
				if ( ! preg_match( '~\bsrc="(' . $basis . '/[^"]+)"~i', $tag, $s ) ) {
					return $tag;
				}
				$url = $s[1];
				$id  = vljcr_design_bild_id( $url );
				if ( preg_match( '/\.svg$/i', $url ) ) {
					if ( preg_match( '/\balt=""/', $tag ) && false !== strpos( $url, 'logo' ) ) {
						$tag = str_replace( 'alt=""', 'alt="' . esc_attr( 'Vereinigung Liberaler Juristen in Niedersachsen e.V.' ) . '"', $tag );
					}
					return $tag;
				}
				if ( ! $id ) {
					return $tag;
				}
				if ( false === stripos( $tag, 'srcset=' ) ) {
					$srcset = wp_get_attachment_image_srcset( $id, 'full' );
					$gross  = wp_get_attachment_image_src( $id, 'large' );
					if ( $srcset ) {
						$neu = ' srcset="' . esc_attr( $srcset ) . '" sizes="(max-width: 767px) 100vw, 50vw"';
						if ( $gross && ! empty( $gross[0] ) ) {
							$tag = str_replace( $s[0], 'src="' . esc_url( $gross[0] ) . '"', $tag );
						}
						$tag = preg_replace( '~<img\b~i', '<img' . $neu, $tag, 1 );
					}
				}
				if ( preg_match( '/\balt=""/', $tag ) || ! preg_match( '/\balt=/', $tag ) ) {
					$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
					if ( '' !== $alt ) {
						$tag = preg_match( '/\balt=""/', $tag ) ? str_replace( 'alt=""', 'alt="' . esc_attr( $alt ) . '"', $tag ) : preg_replace( '~<img\b~i', '<img alt="' . esc_attr( $alt ) . '"', $tag, 1 );
					}
				}
				if ( false === stripos( $tag, 'decoding=' ) ) {
					$tag = preg_replace( '~<img\b~i', '<img decoding="async"', $tag, 1 );
				}
				return $tag;
			},
			$html
		);

		// 3. Nur eine Hauptüberschrift: weitere h1 werden zu h2 (Aussehen bleibt über die Klassen erhalten).
		if ( preg_match_all( '~<h1\b~i', $html ) > 1 ) {
			$n     = 0;
			$stapel = array();
			$html  = preg_replace_callback(
				'~<(/?)h1\b~i',
				function ( $m ) use ( &$n, &$stapel ) {
					if ( '' === $m[1] ) {
						$n++;
						$stapel[] = $n > 1;
						return $n > 1 ? '<h2 data-vlj-h1="1"' : $m[0];
					}
					$war = array_pop( $stapel );
					return $war ? '</h2' : $m[0];
				},
				$html
			);
		}

		// 3b. Keine Hauptüberschrift (Startseite, Termine): der Text im Seitenkopf wird zur h1.
		if ( 0 === preg_match_all( '~<h1\b~i', $html ) ) {
			$a = 0;
			for ( $i = 0; $i < 2; $i++ ) {
				$a = stripos( $html, '<section', $a + ( $i ? 1 : 0 ) );
				if ( false === $a ) {
					break;
				}
			}
			$e = false !== $a ? stripos( $html, '</section>', $a ) : false;
			if ( false !== $a && false !== $e ) {
				$kopf = substr( $html, $a, $e - $a );
				$fertig = false;
				$neu    = preg_replace_callback(
					'~<(p|h2|h3|h4|h5|h6)\b([^>]*)>(.*?)</\1>~is',
					function ( $m ) use ( &$fertig ) {
						if ( $fertig ) {
							return $m[0];
						}
						$t = trim( wp_strip_all_tags( $m[3] ) );
						if ( '' === $t || mb_strlen( $t ) >= 120 ) {
							return $m[0];
						}
						$fertig = true;
						return '<h1' . $m[2] . ' data-vlj-h1="kopf">' . $m[3] . '</h1>';
					},
					$kopf
				);
				if ( is_string( $neu ) && $neu !== $kopf ) {
					$html = substr( $html, 0, $a ) . $neu . substr( $html, $e );
				}
			}
		}

		// 4. Seitenbeschreibung aus dem vorhandenen Text (erster längerer Absatz), ohne Skriptreste.
		$html = preg_replace_callback(
			'~<meta name="description" content="([^"]*)"\s*/?>~i',
			function ( $m ) use ( $html ) {
				$text = '';
				$ende = strpos( $html, 'class="mhcookie' );
				$teil = false !== $ende ? substr( $html, 0, $ende ) : $html;
				if ( false === strpos( $html, 'erecht24-legal-texts-imprint' ) && preg_match_all( '~<p\b[^>]*>(.*?)</p>~is', $teil, $ps ) ) {
					foreach ( $ps[1] as $p ) {
						$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $p ), ENT_QUOTES, 'UTF-8' ) ) );
						if ( mb_strlen( $t ) >= 80 && ! preg_match( '/cookie|datenschutzeinstellung|var\s+\w+\s*=/i', $t ) ) {
							$text = $t;
							break;
						}
					}
				}
				if ( '' === $text ) {
					$titel = preg_match( '~<title[^>]*>(.*?)</title>~is', $html, $tt ) ? html_entity_decode( wp_strip_all_tags( $tt[1] ), ENT_QUOTES, 'UTF-8' ) : '';
					$titel = trim( preg_replace( '/\s*[–|-]\s.*$/u', '', $titel ) );
					$text  = ( '' !== $titel ? $titel . ' – ' : '' ) . 'Vereinigung Liberaler Juristen in Niedersachsen e.V.';
				}
				if ( mb_strlen( $text ) > 158 ) {
					$text = mb_substr( $text, 0, 155 );
					$text = preg_replace( '/\s+\S*$/u', '', $text ) . ' …';
				}
				return '<meta name="description" content="' . esc_attr( $text ) . '" />';
			},
			$html,
			1
		);
		// Große Fassungen der Porträts für die Profilansicht.
		if ( ! empty( $GLOBALS['vljcr_gross'] ) ) {
			$html = preg_replace( '~</body>~i', '<script>window.VLJ_GROSS=' . wp_json_encode( $GLOBALS['vljcr_gross'], JSON_UNESCAPED_SLASHES ) . ';</script></body>', $html, 1 );
		}
	} catch ( \Throwable $e ) {
		return $html;
	}
	return $html;
}

/* ---------- Kalenderdatei (.ics) für einen Termin der Terminseite ---------- */
// Aufruf: /?vlj_kalender=JJJJ-MM-TT. Der Termin wird aus dem Text der Seite „Termine“ gelesen,
// es lassen sich also nur Termine abrufen, die dort stehen.

function vljcr_design_zeilen( $html ) {
	$html = preg_replace( '~<(script|style)\b[^>]*>.*?</\1>~is', '', (string) $html );
	$html = preg_replace( '~<br\s*/?>|</(p|h[1-6]|div|li)>~i', "\n", $html );
	$t    = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES, 'UTF-8' );
	$t    = str_replace( array( "\xC2\xA0", "\xEF\xBB\xBF", "\xC2\xAD" ), array( ' ', '', '' ), $t );
	$out  = array();
	foreach ( preg_split( '/\R/u', $t ) as $z ) {
		$z = trim( preg_replace( '/\s+/u', ' ', $z ) );
		if ( '' !== $z ) {
			$out[] = $z;
		}
	}
	return $out;
}

function vljcr_design_termin_suchen( array $zeilen, $datum ) {
	$monate = array( 'januar', 'februar', 'märz', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'dezember' );
	$titel  = '';
	$n      = count( $zeilen );
	for ( $i = 0; $i < $n; $i++ ) {
		$z = $zeilen[ $i ];
		if ( preg_match( '/^„.+“$/u', $z ) ) {
			$titel = trim( $z, '„“ ' );
			continue;
		}
		if ( '' === $titel || ! preg_match( '/(\d{1,2})\.\s*(Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})/iu', $z, $m ) ) {
			continue;
		}
		$d = sprintf( '%04d-%02d-%02d', (int) $m[3], array_search( mb_strtolower( $m[2] ), $monate, true ) + 1, (int) $m[1] );
		if ( $d !== $datum ) {
			$titel = '';
			continue;
		}
		$ev = array( 'titel' => $titel, 'datum' => $d, 'von' => '', 'bis' => '', 'ort' => '', 'text' => '' );
		$rest = mb_substr( $z, mb_strpos( $z, $m[0] ) );
		if ( preg_match( '/(\d{1,2})[:.](\d{2})(?:\s*[-–]\s*(\d{1,2})[:.](\d{2}))?\s*Uhr/u', $rest, $u ) ) {
			$ev['von'] = sprintf( '%02d:%02d', $u[1], $u[2] );
			if ( ! empty( $u[3] ) ) {
				$ev['bis'] = sprintf( '%02d:%02d', $u[3], $u[4] );
			}
		}
		for ( $j = $i + 1; $j < min( $n, $i + 12 ); $j++ ) {
			if ( preg_match( '/^„.+“$/u', $zeilen[ $j ] ) ) {
				break;
			}
			if ( '' === $ev['von'] && preg_match( '/(\d{1,2})[:.](\d{2})(?:\s*[-–]\s*(\d{1,2})[:.](\d{2}))?\s*Uhr/u', $zeilen[ $j ], $u ) ) {
				$ev['von'] = sprintf( '%02d:%02d', $u[1], $u[2] );
				$ev['bis'] = ! empty( $u[3] ) ? sprintf( '%02d:%02d', $u[3], $u[4] ) : '';
			}
			if ( '' === $ev['ort'] && preg_match( '/^Ort:\s*(.+)$/u', $zeilen[ $j ], $o ) ) {
				$ev['ort'] = $o[1];
			}
			if ( '' === $ev['text'] && mb_strlen( $zeilen[ $j ] ) > 60 && ! preg_match( '/^Ort:/u', $zeilen[ $j ] ) ) {
				$ev['text'] = $zeilen[ $j ];
			}
		}
		return $ev;
	}
	return null;
}

function vljcr_design_ics_text( $s ) {
	return str_replace( array( '\\', "\n", ',', ';' ), array( '\\\\', '\\n', '\\,', '\;' ), (string) $s );
}

function vljcr_design_ics_falten( $z ) {
	$out = array();
	while ( strlen( $z ) > 74 ) {
		$teil = mb_strcut( $z, 0, 74, 'UTF-8' );
		$out[] = $teil;
		$z     = ' ' . substr( $z, strlen( $teil ) );
	}
	$out[] = $z;
	return implode( "\r\n", $out );
}

add_action(
	'template_redirect',
	function () {
		if ( ! isset( $_GET['vlj_kalender'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$datum = sanitize_text_field( wp_unslash( $_GET['vlj_kalender'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
			status_header( 400 );
			exit;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$seite = get_page_by_path( 'termine' );
		$html  = '';
		if ( $seite ) {
			$roh = get_post_meta( $seite->ID, 'brizy-compiled-sections', true );
			if ( is_string( $roh ) && '' !== $roh && function_exists( 'vljcr_bk_auspacken' ) ) {
				$s = array();
				$d = vljcr_bk_auspacken( $roh, $s );
				$d = json_decode( wp_json_encode( $d ), true );
				if ( is_array( $d ) && ! empty( $d['blocks'] ) ) {
					foreach ( (array) $d['blocks'] as $b ) {
						$html .= isset( $b['html'] ) ? (string) $b['html'] : '';
					}
				}
			}
			if ( '' === $html ) {
				$html = (string) $seite->post_content;
			}
		}
		$ev = vljcr_design_termin_suchen( vljcr_design_zeilen( $html ), $datum );
		if ( ! $ev ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Termin nicht gefunden.';
			exit;
		}
		$tz  = new DateTimeZone( 'Europe/Berlin' );
		$utc = new DateTimeZone( 'UTC' );
		$z   = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//VLJ Niedersachsen//Termine//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT' );
		$z[] = 'UID:' . $ev['datum'] . '-' . substr( md5( $ev['titel'] ), 0, 10 ) . '@vlj-niedersachsen.de';
		$z[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
		if ( '' !== $ev['von'] ) {
			$a   = new DateTime( $ev['datum'] . ' ' . $ev['von'], $tz );
			$e   = '' !== $ev['bis'] ? new DateTime( $ev['datum'] . ' ' . $ev['bis'], $tz ) : ( clone $a )->modify( '+2 hours' );
			$z[] = 'DTSTART:' . $a->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			$z[] = 'DTEND:' . $e->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		} else {
			$a   = new DateTime( $ev['datum'], $tz );
			$z[] = 'DTSTART;VALUE=DATE:' . $a->format( 'Ymd' );
			$z[] = 'DTEND;VALUE=DATE:' . ( clone $a )->modify( '+1 day' )->format( 'Ymd' );
		}
		$url = home_url( '/termine/' );
		$z[] = 'SUMMARY:' . vljcr_design_ics_text( $ev['titel'] );
		$z[] = 'DESCRIPTION:' . vljcr_design_ics_text( trim( $ev['text'] . "\n\n" . $url ) );
		if ( '' !== $ev['ort'] ) {
			$z[] = 'LOCATION:' . vljcr_design_ics_text( $ev['ort'] );
		}
		$z[]  = 'URL:' . $url;
		$z[]  = 'END:VEVENT';
		$z[]  = 'END:VCALENDAR';
		$name = sanitize_file_name( 'VLJ-' . $ev['datum'] . '-' . $ev['titel'] ) . '.ics';
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		echo implode( "\r\n", array_map( 'vljcr_design_ics_falten', $z ) ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	},
	0
);
