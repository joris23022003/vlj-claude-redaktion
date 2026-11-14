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
		wp_add_inline_script( 'vljcr-design', 'window.VLJ_CFG=' . wp_json_encode( array( 'm' => (object) $m ) ) . ';', 'before' );
	},
	99
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

		// 1. Hintergrundbilder in den Stilregeln auf eine passende Größe umstellen.
		$html = preg_replace_callback(
			'~(\.brz \.(brz-css-[a-z0-9]+)[^{}]*\{[^{}]*?)url\(\s*(["\']?)(' . $basis . '/[^"\')]+\.(?:jpe?g|png|webp))\3\s*\)~i',
			function ( $m ) use ( $spalten ) {
				$id = vljcr_design_bild_id( $m[4] );
				if ( ! $id ) {
					return $m[0];
				}
				$groesse = isset( $spalten[ $m[2] ] ) ? 'large' : '1536x1536';
				$src     = wp_get_attachment_image_src( $id, $groesse );
				if ( ! $src || empty( $src[0] ) ) {
					return $m[0];
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
	} catch ( \Throwable $e ) {
		return $html;
	}
	return $html;
}
