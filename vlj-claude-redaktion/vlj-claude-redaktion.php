<?php
/**
 * Plugin Name:       VLJ Claude Redaktion
 * Description:       Konnektor für Claude: Claude kann die Website lesen, Entwürfe anlegen, Seiten, Beiträge, Bilder und Menüs ändern. Zugang nur nach Anmeldung mit einem freigeschalteten Microsoft-Konto (Standard: stietenroth@julis-niedersachsen.de). Vor jeder Änderung wird der alte Stand gesichert; alles wird protokolliert.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Joris Stietenroth
 * License:           GPL-2.0-or-later
 * Text Domain:       vlj-claude-redaktion
 * Update URI:        https://github.com/joris23022003/vlj-claude-redaktion
 *
 * Aktualisierung: Neue Versionen kommen als Release (Tag v1.2.0 o. ä. mit angehängter Datei
 * vlj-claude-redaktion-<version>.zip) aus dem öffentlichen GitHub-Repository oben. WordPress prüft
 * zweimal täglich und spielt sie bei eingeschalteter automatischer Aktualisierung selbst ein.
 * Steht im Release-Text eine Zeile "sha256: <prüfsumme>", wird die Datei vor dem Einspielen geprüft.
 *
 * Aufbau
 * - MCP-Server (Streamable HTTP, zustandslos, JSON-RPC 2.0) unter /wp-json/vlj-claude/v1/mcp
 * - Geschützt per OAuth 2.1 (Autorisierungscode mit PKCE S256, dynamische Client-Registrierung),
 *   wie es Claude für eigene Konnektoren erwartet. Das Plugin ist selbst der Autorisierungsserver.
 * - Die Person meldet sich dabei bei Microsoft Entra an. Zugelassen wird nur, wer im eingestellten
 *   Mandanten (JuLis Niedersachsen) angemeldet ist UND dessen Objekt-ID (bzw. Adresse) in der Liste
 *   "Erlaubte Konten" steht. Alle anderen bekommen keinen Token.
 * - Zugangstoken 1 Stunde, Erneuerungstoken 90 Tage (bei jeder Erneuerung neu). Bei jedem Aufruf wird
 *   geprüft, ob das Konto noch erlaubt ist. "Alle Anmeldungen widerrufen" sperrt sofort.
 * - Alle Aktionen laufen mit den Rechten des eingestellten WordPress-Benutzers.
 *   Kein Zugriff auf Benutzer, Plugins, Themes oder Einstellungen der Website.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VLJCR_VERSION', '1.3.0' );
define( 'VLJCR_NS', 'vlj-claude/v1' );
define( 'VLJCR_MAX_SICHERUNGEN', 20 );
define( 'VLJCR_MAX_PROTOKOLL', 300 );
define( 'VLJCR_SCOPE', 'vlj' );
define( 'VLJCR_ACCESS_TTL', HOUR_IN_SECONDS );
define( 'VLJCR_REFRESH_TTL', 90 * DAY_IN_SECONDS );
// Voreinstellungen: Mandant Junge Liberale Niedersachsen e.V. und Konto stietenroth@ (Objekt-ID aus Entra).
define( 'VLJCR_STANDARD_MANDANT', 'b07b34b1-ec58-4a8b-8b62-b616df30098d' );
define( 'VLJCR_STANDARD_KONTEN', "stietenroth@julis-niedersachsen.de aac101b4-750c-41b7-a83e-afb391055987" );

/* ------------------------------------------------------------------ */
/* Aktivierung, Optionen, Protokoll                                    */
/* ------------------------------------------------------------------ */

register_activation_hook( __FILE__, 'vljcr_aktivieren' );
register_uninstall_hook( __FILE__, 'vljcr_deinstallieren' );

function vljcr_optionen() {
	return array( 'vljcr_user', 'vljcr_aktiv', 'vljcr_protokoll', 'vljcr_ms_mandant', 'vljcr_ms_client_id', 'vljcr_ms_secret', 'vljcr_konten', 'vljcr_clients', 'vljcr_tokens', 'vljcr_key_read', 'vljcr_key_write', 'vljcr_auto_update_gesetzt' );
}

function vljcr_aktivieren() {
	if ( ! get_option( 'vljcr_user' ) ) {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$uid    = $admins ? (int) $admins[0] : 0;
		}
		update_option( 'vljcr_user', (int) $uid, false );
	}
	add_option( 'vljcr_aktiv', 1, '', false );
	add_option( 'vljcr_protokoll', array(), '', false );
	add_option( 'vljcr_ms_mandant', VLJCR_STANDARD_MANDANT, '', false );
	add_option( 'vljcr_ms_client_id', '', '', false );
	add_option( 'vljcr_ms_secret', '', '', false );
	add_option( 'vljcr_konten', VLJCR_STANDARD_KONTEN, '', false );
	add_option( 'vljcr_clients', array(), '', false );
	add_option( 'vljcr_tokens', array(), '', false );
	// Geheime Adressen aus 1.0.0 gibt es nicht mehr.
	delete_option( 'vljcr_key_read' );
	delete_option( 'vljcr_key_write' );
	// Automatische Aktualisierung einmalig einschalten (lässt sich in der Plugin-Liste wieder ausschalten).
	if ( ! get_option( 'vljcr_auto_update_gesetzt' ) ) {
		$auto = (array) get_site_option( 'auto_update_plugins', array() );
		$ich  = plugin_basename( __FILE__ );
		if ( ! in_array( $ich, $auto, true ) ) {
			$auto[] = $ich;
			update_site_option( 'auto_update_plugins', $auto );
		}
		update_option( 'vljcr_auto_update_gesetzt', 1, false );
	}
}

// Auch beim Aktualisieren ohne erneutes Aktivieren die Optionen anlegen.
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'vljcr_version' ) !== VLJCR_VERSION ) {
			vljcr_aktivieren();
			update_option( 'vljcr_version', VLJCR_VERSION, false );
		}
	}
);

/* ------------------------------------------------------------------ */
/* Automatische Aktualisierung über GitHub-Releases                    */
/* ------------------------------------------------------------------ */

define( 'VLJCR_REPO', 'joris23022003/vlj-claude-redaktion' );

function vljcr_release() {
	$r = get_site_transient( 'vljcr_release' );
	if ( is_array( $r ) ) {
		return $r['version'] ? $r : null;
	}
	$api = apply_filters( 'vljcr_update_api', 'https://api.github.com/repos/' . VLJCR_REPO . '/releases/latest' );
	$a   = wp_remote_get( $api, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'vlj-claude-redaktion/' . VLJCR_VERSION ) ) );
	$d   = is_wp_error( $a ) ? null : json_decode( (string) wp_remote_retrieve_body( $a ), true );
	$out = array( 'version' => '' );
	if ( is_array( $d ) && ! empty( $d['tag_name'] ) && empty( $d['draft'] ) && empty( $d['prerelease'] ) ) {
		$version = ltrim( (string) $d['tag_name'], 'vV' );
		foreach ( (array) ( isset( $d['assets'] ) ? $d['assets'] : array() ) as $asset ) {
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( preg_match( '/^vlj-claude-redaktion.*\.zip$/', $name ) && ! empty( $asset['browser_download_url'] ) ) {
				$sha = preg_match( '/sha256:\s*([0-9a-f]{64})/i', (string) ( isset( $d['body'] ) ? $d['body'] : '' ), $m ) ? strtolower( $m[1] ) : '';
				$out = array(
					'version' => $version,
					'paket'   => (string) $asset['browser_download_url'],
					'seite'   => isset( $d['html_url'] ) ? (string) $d['html_url'] : 'https://github.com/' . VLJCR_REPO,
					'text'    => isset( $d['body'] ) ? (string) $d['body'] : '',
					'sha256'  => $sha,
				);
				break;
			}
		}
	}
	// Erfolg 6 Stunden merken, Fehler 15 Minuten (GitHub-Limit für Aufrufe ohne Anmeldung: 60 je Stunde).
	set_site_transient( 'vljcr_release', $out, $out['version'] ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
	return $out['version'] ? $out : null;
}

add_filter(
	'update_plugins_github.com',
	function ( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return $update;
		}
		$r = vljcr_release();
		if ( ! $r ) {
			// Ohne Release trotzdem melden, dass es eine Update-Quelle gibt (zeigt den Schalter für automatische Updates).
			return array( 'slug' => 'vlj-claude-redaktion', 'version' => VLJCR_VERSION, 'url' => 'https://github.com/' . VLJCR_REPO, 'package' => '' );
		}
		return array(
			'id'           => 'github.com/' . VLJCR_REPO,
			'slug'         => 'vlj-claude-redaktion',
			'version'      => $r['version'],
			'url'          => $r['seite'],
			'package'      => $r['paket'],
			'requires_php' => '7.4',
		);
	},
	10,
	3
);

// Prüfsumme aus dem Release-Text vor dem Einspielen kontrollieren.
add_filter(
	'upgrader_pre_download',
	function ( $antwort, $paket ) {
		$r = vljcr_release();
		if ( false !== $antwort || ! $r || $paket !== $r['paket'] || ! $r['sha256'] ) {
			return $antwort;
		}
		$datei = download_url( $paket, 120 );
		if ( is_wp_error( $datei ) ) {
			return $datei;
		}
		if ( ! hash_equals( $r['sha256'], hash_file( 'sha256', $datei ) ) ) {
			@unlink( $datei );
			return new WP_Error( 'vljcr_pruefsumme', 'VLJ Claude Redaktion: Prüfsumme des Updates passt nicht. Update abgebrochen.' );
		}
		return $datei;
	},
	10,
	2
);

// Nach einem Update sofort neu prüfen; Details-Fenster mit Release-Text.
add_action(
	'upgrader_process_complete',
	function () {
		delete_site_transient( 'vljcr_release' );
	}
);
add_filter(
	'plugins_api',
	function ( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'vlj-claude-redaktion' !== $args->slug ) {
			return $res;
		}
		$r = vljcr_release();
		return (object) array(
			'name'          => 'VLJ Claude Redaktion',
			'slug'          => 'vlj-claude-redaktion',
			'version'       => $r ? $r['version'] : VLJCR_VERSION,
			'author'        => 'Joris Stietenroth',
			'homepage'      => 'https://github.com/' . VLJCR_REPO,
			'download_link' => $r ? $r['paket'] : '',
			'sections'      => array( 'changelog' => $r ? nl2br( esc_html( $r['text'] ) ) : 'Keine Angaben.' ),
		);
	},
	10,
	3
);

function vljcr_deinstallieren() {
	foreach ( vljcr_optionen() as $o ) {
		delete_option( $o );
	}
	delete_option( 'vljcr_version' );
}

function vljcr_protokoll( $werkzeug, $zugang, $args, $ergebnis, $fehler = false ) {
	$log   = get_option( 'vljcr_protokoll', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array(
		'zeit'     => current_time( 'mysql' ),
		'werkzeug' => $werkzeug,
		'zugang'   => $zugang,
		'id'       => isset( $args['id'] ) ? (int) $args['id'] : null,
		'freigabe' => isset( $args['freigabe'] ) ? mb_substr( (string) $args['freigabe'], 0, 200 ) : '',
		'ergebnis' => mb_substr( (string) $ergebnis, 0, 300 ),
		'fehler'   => (bool) $fehler,
	);
	if ( count( $log ) > VLJCR_MAX_PROTOKOLL ) {
		$log = array_slice( $log, -VLJCR_MAX_PROTOKOLL );
	}
	update_option( 'vljcr_protokoll', $log, false );
}

/* ------------------------------------------------------------------ */
/* Adressen                                                            */
/* ------------------------------------------------------------------ */

function vljcr_issuer() {
	return untrailingslashit( rest_url( VLJCR_NS ) );
}
function vljcr_mcp_url() {
	return vljcr_issuer() . '/mcp';
}
function vljcr_prm_url() {
	return vljcr_issuer() . '/.well-known/oauth-protected-resource';
}
function vljcr_ms_redirect_uri() {
	return vljcr_issuer() . '/oauth/microsoft';
}
function vljcr_ms_basis() {
	// Für Tests austauschbar.
	return apply_filters( 'vljcr_ms_basis', 'https://login.microsoftonline.com' );
}

function vljcr_as_metadaten() {
	$i = vljcr_issuer();
	return array(
		'issuer'                                => $i,
		'authorization_endpoint'                => $i . '/oauth/authorize',
		'token_endpoint'                        => $i . '/oauth/token',
		'registration_endpoint'                 => $i . '/oauth/register',
		'revocation_endpoint'                   => $i . '/oauth/revoke',
		'response_types_supported'              => array( 'code' ),
		'response_modes_supported'              => array( 'query' ),
		'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
		'code_challenge_methods_supported'      => array( 'S256' ),
		'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
		'scopes_supported'                      => array( VLJCR_SCOPE ),
		'service_documentation'                 => home_url( '/' ),
	);
}

function vljcr_prm_metadaten() {
	return array(
		'resource'                 => vljcr_mcp_url(),
		'authorization_servers'    => array( vljcr_issuer() ),
		'bearer_methods_supported' => array( 'header' ),
		'scopes_supported'         => array( VLJCR_SCOPE ),
		'resource_name'            => 'VLJ Website (Redaktion)',
	);
}

/* ------------------------------------------------------------------ */
/* .well-known an der Wurzel der Domain (RFC 8414 / RFC 9728)          */
/* ------------------------------------------------------------------ */

add_action( 'init', 'vljcr_wellknown_wurzel', 0 );

function vljcr_wellknown_wurzel() {
	if ( empty( $_SERVER['REQUEST_URI'] ) || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
		return;
	}
	$pfad = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( $home && '/' !== $home && 0 === strpos( $pfad, untrailingslashit( $home ) ) ) {
		$pfad = substr( $pfad, strlen( untrailingslashit( $home ) ) );
	}
	if ( 0 !== strpos( $pfad, '/.well-known/' ) ) {
		return;
	}
	$issuer_pfad = untrailingslashit( (string) wp_parse_url( vljcr_issuer(), PHP_URL_PATH ) );
	$mcp_pfad    = (string) wp_parse_url( vljcr_mcp_url(), PHP_URL_PATH );
	$daten       = null;
	foreach ( array( 'oauth-authorization-server', 'openid-configuration' ) as $art ) {
		$basis = '/.well-known/' . $art;
		if ( $pfad === $basis . $issuer_pfad || ( 'oauth-authorization-server' === $art && ( $pfad === $basis || $pfad === $basis . '/' ) ) ) {
			$daten = vljcr_as_metadaten();
		}
	}
	$basis = '/.well-known/oauth-protected-resource';
	if ( $pfad === $basis . $mcp_pfad || $pfad === $basis . $issuer_pfad || $pfad === $basis || $pfad === $basis . '/' ) {
		$daten = vljcr_prm_metadaten();
	}
	if ( null === $daten ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: no-store' );
	echo wp_json_encode( $daten, JSON_UNESCAPED_SLASHES );
	exit;
}

/* ------------------------------------------------------------------ */
/* REST-Routen                                                         */
/* ------------------------------------------------------------------ */

add_action(
	'rest_api_init',
	function () {
		$offen = '__return_true';
		register_rest_route( VLJCR_NS, '/mcp', array( 'methods' => array( 'GET', 'POST', 'DELETE' ), 'callback' => 'vljcr_rest', 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/.well-known/oauth-protected-resource', array( 'methods' => 'GET', 'callback' => function () { return vljcr_json( vljcr_prm_metadaten() ); }, 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/.well-known/oauth-authorization-server', array( 'methods' => 'GET', 'callback' => function () { return vljcr_json( vljcr_as_metadaten() ); }, 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/.well-known/openid-configuration', array( 'methods' => 'GET', 'callback' => function () { return vljcr_json( vljcr_as_metadaten() ); }, 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/oauth/register', array( 'methods' => 'POST', 'callback' => 'vljcr_oauth_register', 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/oauth/authorize', array( 'methods' => 'GET', 'callback' => 'vljcr_oauth_authorize', 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/oauth/microsoft', array( 'methods' => 'GET', 'callback' => 'vljcr_oauth_microsoft', 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/oauth/token', array( 'methods' => 'POST', 'callback' => 'vljcr_oauth_token', 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/oauth/revoke', array( 'methods' => 'POST', 'callback' => 'vljcr_oauth_revoke', 'permission_callback' => $offen ) );
		register_rest_route( VLJCR_NS, '/diagnose/kopf', array( 'methods' => 'GET', 'callback' => function () { return vljcr_json( array( 'authorization_angekommen' => '' !== vljcr_auth_kopf() ) ); }, 'permission_callback' => $offen ) );
	}
);

function vljcr_json( $daten, $status = 200 ) {
	$r = new WP_REST_Response( $daten, $status );
	$r->header( 'Cache-Control', 'no-store' );
	$r->header( 'Pragma', 'no-cache' );
	$r->header( 'Access-Control-Allow-Origin', '*' );
	return $r;
}

function vljcr_oauth_fehler( $code, $text, $status = 400 ) {
	return vljcr_json( array( 'error' => $code, 'error_description' => $text ), $status );
}

/** Authorization-Kopf auch dann finden, wenn der Server ihn umbenennt. */
function vljcr_auth_kopf() {
	foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			return trim( (string) $_SERVER[ $k ] );
		}
	}
	if ( function_exists( 'getallheaders' ) ) {
		foreach ( (array) getallheaders() as $k => $v ) {
			if ( 'authorization' === strtolower( $k ) ) {
				return trim( (string) $v );
			}
		}
	}
	return '';
}

function vljcr_zufall( $bytes = 32 ) {
	return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
}
function vljcr_hash( $wert ) {
	return hash( 'sha256', (string) $wert );
}
function vljcr_b64url_dekodieren( $s ) {
	$s = strtr( (string) $s, '-_', '+/' );
	return base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
}

/* ------------------------------------------------------------------ */
/* Erlaubte Konten                                                     */
/* ------------------------------------------------------------------ */

/** Liste aus Zeilen "adresse objekt-id" (eines von beiden genügt). */
function vljcr_konten() {
	$out = array();
	foreach ( preg_split( '/\r?\n/', (string) get_option( 'vljcr_konten', VLJCR_STANDARD_KONTEN ) ) as $z ) {
		$z = trim( $z );
		if ( '' === $z || '#' === $z[0] ) {
			continue;
		}
		$k = array( 'mail' => '', 'oid' => '' );
		foreach ( preg_split( '/[\s,;]+/', $z ) as $teil ) {
			if ( false !== strpos( $teil, '@' ) ) {
				$k['mail'] = strtolower( $teil );
			} elseif ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $teil ) ) {
				$k['oid'] = strtolower( $teil );
			}
		}
		if ( $k['mail'] || $k['oid'] ) {
			$out[] = $k;
		}
	}
	return $out;
}

/** Ist dieses Konto erlaubt? Mit Objekt-ID wird nur diese geprüft (unveränderlich), sonst die Adresse. */
function vljcr_konto_erlaubt( $oid, $mail ) {
	$oid  = strtolower( (string) $oid );
	$mail = strtolower( (string) $mail );
	foreach ( vljcr_konten() as $k ) {
		if ( $k['oid'] ) {
			if ( $oid && hash_equals( $k['oid'], $oid ) ) {
				return true;
			}
		} elseif ( $k['mail'] && $mail && hash_equals( $k['mail'], $mail ) ) {
			return true;
		}
	}
	return false;
}

/* ------------------------------------------------------------------ */
/* OAuth: Client-Registrierung (RFC 7591)                              */
/* ------------------------------------------------------------------ */

function vljcr_redirect_erlaubt( $uri ) {
	$teile = wp_parse_url( (string) $uri );
	if ( ! $teile || empty( $teile['scheme'] ) || empty( $teile['host'] ) || ! empty( $teile['fragment'] ) ) {
		return false;
	}
	$host = strtolower( $teile['host'] );
	if ( 'http' === $teile['scheme'] ) {
		return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}
	if ( 'https' !== $teile['scheme'] ) {
		return false;
	}
	// Nur Claude (claude.ai, claude.com) und eigene, ausdrücklich ergänzte Ziele.
	$erlaubt = apply_filters( 'vljcr_redirect_hosts', array( 'claude.ai', 'claude.com' ) );
	foreach ( $erlaubt as $h ) {
		if ( $host === $h || substr( $host, - ( strlen( $h ) + 1 ) ) === '.' . $h ) {
			return true;
		}
	}
	return false;
}

function vljcr_oauth_register( WP_REST_Request $request ) {
	$d = $request->get_json_params();
	if ( ! is_array( $d ) ) {
		return vljcr_oauth_fehler( 'invalid_client_metadata', 'JSON erwartet.' );
	}
	$uris = isset( $d['redirect_uris'] ) && is_array( $d['redirect_uris'] ) ? array_values( array_filter( array_map( 'strval', $d['redirect_uris'] ) ) ) : array();
	if ( ! $uris ) {
		return vljcr_oauth_fehler( 'invalid_redirect_uri', 'redirect_uris fehlt.' );
	}
	foreach ( $uris as $u ) {
		if ( ! vljcr_redirect_erlaubt( $u ) ) {
			return vljcr_oauth_fehler( 'invalid_redirect_uri', 'Weiterleitungsziel nicht erlaubt: ' . $u );
		}
	}
	$methode = isset( $d['token_endpoint_auth_method'] ) ? (string) $d['token_endpoint_auth_method'] : 'none';
	if ( ! in_array( $methode, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
		$methode = 'none';
	}
	$client_id = 'vljcr_' . vljcr_zufall( 18 );
	$secret    = 'none' === $methode ? '' : vljcr_zufall( 32 );
	$jetzt     = time();
	$clients   = (array) get_option( 'vljcr_clients', array() );
	$clients[ $client_id ] = array(
		'name'          => isset( $d['client_name'] ) ? mb_substr( sanitize_text_field( (string) $d['client_name'] ), 0, 100 ) : '',
		'redirect_uris' => $uris,
		'methode'       => $methode,
		'secret_hash'   => $secret ? vljcr_hash( $secret ) : '',
		'erstellt'      => $jetzt,
	);
	if ( count( $clients ) > 30 ) {
		uasort( $clients, function ( $a, $b ) { return $a['erstellt'] - $b['erstellt']; } );
		$clients = array_slice( $clients, -30, null, true );
	}
	update_option( 'vljcr_clients', $clients, false );

	$antwort = array(
		'client_id'                  => $client_id,
		'client_id_issued_at'        => $jetzt,
		'client_name'                => $clients[ $client_id ]['name'],
		'redirect_uris'              => $uris,
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		'response_types'             => array( 'code' ),
		'token_endpoint_auth_method' => $methode,
		'scope'                      => VLJCR_SCOPE,
	);
	if ( $secret ) {
		$antwort['client_secret']            = $secret;
		$antwort['client_secret_expires_at'] = 0;
	}
	return vljcr_json( $antwort, 201 );
}

function vljcr_client( $client_id ) {
	$clients = (array) get_option( 'vljcr_clients', array() );
	return isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
}

/* ------------------------------------------------------------------ */
/* OAuth: Autorisierung mit Anmeldung bei Microsoft                    */
/* ------------------------------------------------------------------ */

function vljcr_html_seite( $titel, $text, $status = 200 ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	status_header( $status );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'X-Frame-Options: DENY' );
	echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $titel ) . '</title>'
		. '<style>body{font-family:system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem;color:#222;line-height:1.5}h1{font-size:1.4rem}</style></head><body>'
		. '<h1>' . esc_html( $titel ) . '</h1><p>' . wp_kses( $text, array( 'br' => array(), 'strong' => array(), 'code' => array() ) ) . '</p></body></html>';
	exit;
}

function vljcr_umleiten( $url ) {
	header( 'Cache-Control: no-store' );
	header( 'Location: ' . $url, true, 302 );
	exit;
}

function vljcr_url_mit( $basis, array $params ) {
	return $basis . ( false === strpos( $basis, '?' ) ? '?' : '&' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
}

function vljcr_oauth_authorize( WP_REST_Request $r ) {
	$client_id = (string) $r->get_param( 'client_id' );
	$redirect  = (string) $r->get_param( 'redirect_uri' );
	$client    = vljcr_client( $client_id );
	if ( ! $client ) {
		vljcr_html_seite( 'Anmeldung nicht möglich', 'Unbekannter Client. Bitte den Konnektor in Claude neu verbinden.', 400 );
	}
	if ( '' === $redirect && 1 === count( $client['redirect_uris'] ) ) {
		$redirect = $client['redirect_uris'][0];
	}
	if ( ! in_array( $redirect, $client['redirect_uris'], true ) ) {
		vljcr_html_seite( 'Anmeldung nicht möglich', 'Das Weiterleitungsziel passt nicht zum registrierten Client.', 400 );
	}
	$state = (string) $r->get_param( 'state' );
	$fehler = function ( $code, $text ) use ( $redirect, $state ) {
		$p = array( 'error' => $code, 'error_description' => $text );
		if ( '' !== $state ) {
			$p['state'] = $state;
		}
		vljcr_umleiten( vljcr_url_mit( $redirect, $p ) );
	};
	if ( 'code' !== $r->get_param( 'response_type' ) ) {
		$fehler( 'unsupported_response_type', 'Nur response_type=code.' );
	}
	$challenge = (string) $r->get_param( 'code_challenge' );
	if ( '' === $challenge || 'S256' !== $r->get_param( 'code_challenge_method' ) ) {
		$fehler( 'invalid_request', 'PKCE mit S256 ist Pflicht.' );
	}
	if ( ! get_option( 'vljcr_aktiv' ) ) {
		$fehler( 'access_denied', 'Der Konnektor ist auf der Website ausgeschaltet.' );
	}
	$ms_client = (string) get_option( 'vljcr_ms_client_id' );
	$mandant   = (string) get_option( 'vljcr_ms_mandant' );
	if ( '' === $ms_client || '' === (string) get_option( 'vljcr_ms_secret' ) || '' === $mandant ) {
		vljcr_html_seite( 'Noch nicht eingerichtet', 'Auf der Website fehlt die Microsoft-Anmeldung (WordPress &gt; Werkzeuge &gt; Claude Redaktion: Client-ID und Geheimnis).', 503 );
	}

	$ms_state    = vljcr_zufall( 24 );
	$ms_verifier = vljcr_zufall( 48 );
	$nonce       = vljcr_zufall( 18 );
	set_transient(
		'vljcr_az_' . vljcr_hash( $ms_state ),
		array(
			'client_id'   => $client_id,
			'redirect'    => $redirect,
			'state'       => $state,
			'challenge'   => $challenge,
			'scope'       => VLJCR_SCOPE,
			'resource'    => (string) $r->get_param( 'resource' ),
			'ms_verifier' => $ms_verifier,
			'nonce'       => $nonce,
		),
		15 * MINUTE_IN_SECONDS
	);

	$hinweis = '';
	foreach ( vljcr_konten() as $k ) {
		if ( $k['mail'] ) {
			$hinweis = $k['mail'];
			break;
		}
	}
	$params = array(
		'client_id'             => $ms_client,
		'response_type'         => 'code',
		'redirect_uri'          => vljcr_ms_redirect_uri(),
		'response_mode'         => 'query',
		'scope'                 => 'openid profile email',
		'state'                 => $ms_state,
		'nonce'                 => $nonce,
		'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $ms_verifier, true ) ), '+/', '-_' ), '=' ),
		'code_challenge_method' => 'S256',
		'prompt'                => 'select_account',
	);
	if ( $hinweis ) {
		$params['login_hint'] = $hinweis;
	}
	vljcr_umleiten( vljcr_url_mit( vljcr_ms_basis() . '/' . rawurlencode( $mandant ) . '/oauth2/v2.0/authorize', $params ) );
}

function vljcr_oauth_microsoft( WP_REST_Request $r ) {
	$ms_state = (string) $r->get_param( 'state' );
	$schl     = 'vljcr_az_' . vljcr_hash( $ms_state );
	$a        = '' !== $ms_state ? get_transient( $schl ) : false;
	if ( ! $a ) {
		vljcr_html_seite( 'Anmeldung abgelaufen', 'Die Anmeldung ist abgelaufen oder wurde schon benutzt. Bitte in Claude erneut auf „Verbinden“ tippen.', 400 );
	}
	delete_transient( $schl );
	$zurueck = function ( array $p ) use ( $a ) {
		if ( '' !== $a['state'] ) {
			$p['state'] = $a['state'];
		}
		vljcr_umleiten( vljcr_url_mit( $a['redirect'], $p ) );
	};
	if ( $r->get_param( 'error' ) ) {
		$zurueck( array( 'error' => 'access_denied', 'error_description' => 'Microsoft: ' . sanitize_text_field( (string) $r->get_param( 'error_description' ) ) ) );
	}
	$code    = (string) $r->get_param( 'code' );
	$mandant = (string) get_option( 'vljcr_ms_mandant' );
	$antwort = wp_remote_post(
		vljcr_ms_basis() . '/' . rawurlencode( $mandant ) . '/oauth2/v2.0/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'client_id'     => (string) get_option( 'vljcr_ms_client_id' ),
				'client_secret' => (string) get_option( 'vljcr_ms_secret' ),
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => vljcr_ms_redirect_uri(),
				'code_verifier' => $a['ms_verifier'],
				'scope'         => 'openid profile email',
			),
		)
	);
	if ( is_wp_error( $antwort ) ) {
		vljcr_protokoll( 'anmeldung', 'oauth', array(), 'Microsoft nicht erreichbar: ' . $antwort->get_error_message(), true );
		vljcr_html_seite( 'Anmeldung fehlgeschlagen', 'Microsoft war nicht erreichbar. Bitte später erneut versuchen.', 502 );
	}
	$daten = json_decode( (string) wp_remote_retrieve_body( $antwort ), true );
	if ( empty( $daten['id_token'] ) ) {
		$msg = isset( $daten['error_description'] ) ? (string) $daten['error_description'] : 'keine Antwort';
		vljcr_protokoll( 'anmeldung', 'oauth', array(), 'Microsoft-Token fehlgeschlagen: ' . mb_substr( $msg, 0, 200 ), true );
		vljcr_html_seite( 'Anmeldung fehlgeschlagen', 'Microsoft hat die Anmeldung nicht bestätigt: ' . esc_html( mb_substr( $msg, 0, 300 ) ), 502 );
	}
	// Das ID-Token kommt direkt per TLS vom Token-Endpunkt von Microsoft (OpenID Connect Core 3.1.3.7, Nr. 6):
	// Prüfung von Aussteller, Empfänger, Mandant, Ablauf und Nonce genügt.
	$teile = explode( '.', (string) $daten['id_token'] );
	$claims = 3 === count( $teile ) ? json_decode( (string) vljcr_b64url_dekodieren( $teile[1] ), true ) : null;
	$tid    = is_array( $claims ) && isset( $claims['tid'] ) ? strtolower( (string) $claims['tid'] ) : '';
	$ok     = is_array( $claims )
		&& isset( $claims['aud'], $claims['iss'], $claims['exp'], $claims['nonce'] )
		&& hash_equals( (string) get_option( 'vljcr_ms_client_id' ), (string) $claims['aud'] )
		&& hash_equals( strtolower( $mandant ), $tid )
		&& rtrim( (string) $claims['iss'], '/' ) === 'https://login.microsoftonline.com/' . $tid . '/v2.0'
		&& (int) $claims['exp'] > time() - 60
		&& hash_equals( $a['nonce'], (string) $claims['nonce'] );
	if ( ! $ok ) {
		vljcr_protokoll( 'anmeldung', 'oauth', array(), 'ID-Token ungültig (Mandant, Empfänger, Aussteller oder Nonce).', true );
		$zurueck( array( 'error' => 'access_denied', 'error_description' => 'Anmeldung nicht gültig.' ) );
	}
	$oid  = isset( $claims['oid'] ) ? (string) $claims['oid'] : '';
	$mail = isset( $claims['preferred_username'] ) ? (string) $claims['preferred_username'] : ( isset( $claims['email'] ) ? (string) $claims['email'] : '' );
	if ( ! vljcr_konto_erlaubt( $oid, $mail ) ) {
		vljcr_protokoll( 'anmeldung', 'oauth', array(), 'ABGELEHNT: ' . $mail . ' (' . $oid . ') ist nicht freigeschaltet.', true );
		vljcr_html_seite( 'Kein Zugang', 'Das Konto <strong>' . esc_html( $mail ) . '</strong> ist für diesen Konnektor nicht freigeschaltet.', 403 );
	}

	$code_neu = vljcr_zufall( 32 );
	set_transient(
		'vljcr_code_' . vljcr_hash( $code_neu ),
		array(
			'client_id' => $a['client_id'],
			'redirect'  => $a['redirect'],
			'challenge' => $a['challenge'],
			'scope'     => $a['scope'],
			'oid'       => $oid,
			'mail'      => $mail,
		),
		5 * MINUTE_IN_SECONDS
	);
	vljcr_protokoll( 'anmeldung', 'oauth', array(), 'Angemeldet: ' . $mail . ' (Client ' . $a['client_id'] . ')' );
	$zurueck( array( 'code' => $code_neu ) );
}

/* ------------------------------------------------------------------ */
/* OAuth: Token                                                        */
/* ------------------------------------------------------------------ */

function vljcr_tokens_laden() {
	$t     = (array) get_option( 'vljcr_tokens', array() );
	$jetzt = time();
	foreach ( $t as $h => $d ) {
		if ( ! is_array( $d ) || $d['exp'] < $jetzt ) {
			unset( $t[ $h ] );
		}
	}
	return $t;
}

function vljcr_tokens_ausstellen( array $basis, $alter_refresh_hash = '' ) {
	$t = vljcr_tokens_laden();
	if ( $alter_refresh_hash ) {
		unset( $t[ $alter_refresh_hash ] );
	}
	$access  = vljcr_zufall( 32 );
	$refresh = vljcr_zufall( 40 );
	$jetzt   = time();
	$t[ vljcr_hash( $access ) ]  = $basis + array( 'typ' => 'access', 'exp' => $jetzt + VLJCR_ACCESS_TTL );
	$t[ vljcr_hash( $refresh ) ] = $basis + array( 'typ' => 'refresh', 'exp' => $jetzt + VLJCR_REFRESH_TTL );
	update_option( 'vljcr_tokens', $t, false );
	return array(
		'access_token'  => $access,
		'token_type'    => 'Bearer',
		'expires_in'    => VLJCR_ACCESS_TTL,
		'refresh_token' => $refresh,
		'scope'         => VLJCR_SCOPE,
	);
}

function vljcr_client_pruefen( WP_REST_Request $r ) {
	$id     = (string) $r->get_param( 'client_id' );
	$secret = (string) $r->get_param( 'client_secret' );
	$kopf   = vljcr_auth_kopf();
	if ( preg_match( '/^Basic\s+(\S+)$/i', $kopf, $m ) ) {
		$paar = explode( ':', (string) base64_decode( $m[1] ), 2 );
		$id     = rawurldecode( $paar[0] );
		$secret = isset( $paar[1] ) ? rawurldecode( $paar[1] ) : '';
	}
	$c = vljcr_client( $id );
	if ( ! $c ) {
		return array( null, 'Unbekannter Client.' );
	}
	if ( 'none' !== $c['methode'] && ( '' === $secret || ! hash_equals( $c['secret_hash'], vljcr_hash( $secret ) ) ) ) {
		return array( null, 'Client-Geheimnis falsch.' );
	}
	return array( $id, '' );
}

function vljcr_oauth_token( WP_REST_Request $r ) {
	list( $client_id, $fehler ) = vljcr_client_pruefen( $r );
	if ( ! $client_id ) {
		return vljcr_oauth_fehler( 'invalid_client', $fehler, 401 );
	}
	$grant = (string) $r->get_param( 'grant_type' );

	if ( 'authorization_code' === $grant ) {
		$code = (string) $r->get_param( 'code' );
		$schl = 'vljcr_code_' . vljcr_hash( $code );
		$c    = '' !== $code ? get_transient( $schl ) : false;
		if ( ! $c ) {
			return vljcr_oauth_fehler( 'invalid_grant', 'Code ungültig oder abgelaufen.' );
		}
		delete_transient( $schl );
		if ( $c['client_id'] !== $client_id ) {
			return vljcr_oauth_fehler( 'invalid_grant', 'Code gehört zu einem anderen Client.' );
		}
		$redirect = (string) $r->get_param( 'redirect_uri' );
		if ( '' !== $redirect && $redirect !== $c['redirect'] ) {
			return vljcr_oauth_fehler( 'invalid_grant', 'redirect_uri passt nicht.' );
		}
		$verifier = (string) $r->get_param( 'code_verifier' );
		$pruef    = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( '' === $verifier || ! hash_equals( $c['challenge'], $pruef ) ) {
			return vljcr_oauth_fehler( 'invalid_grant', 'PKCE-Prüfung fehlgeschlagen.' );
		}
		if ( ! vljcr_konto_erlaubt( $c['oid'], $c['mail'] ) ) {
			return vljcr_oauth_fehler( 'invalid_grant', 'Konto nicht mehr freigeschaltet.' );
		}
		return vljcr_json( vljcr_tokens_ausstellen( array( 'client_id' => $client_id, 'oid' => $c['oid'], 'mail' => $c['mail'] ) ) );
	}

	if ( 'refresh_token' === $grant ) {
		$rt   = (string) $r->get_param( 'refresh_token' );
		$h    = vljcr_hash( $rt );
		$alle = vljcr_tokens_laden();
		$d    = isset( $alle[ $h ] ) ? $alle[ $h ] : null;
		if ( ! $d || 'refresh' !== $d['typ'] || $d['client_id'] !== $client_id ) {
			return vljcr_oauth_fehler( 'invalid_grant', 'Erneuerungstoken ungültig.' );
		}
		if ( ! get_option( 'vljcr_aktiv' ) || ! vljcr_konto_erlaubt( $d['oid'], $d['mail'] ) ) {
			return vljcr_oauth_fehler( 'invalid_grant', 'Zugang gesperrt.' );
		}
		return vljcr_json( vljcr_tokens_ausstellen( array( 'client_id' => $client_id, 'oid' => $d['oid'], 'mail' => $d['mail'] ), $h ) );
	}

	return vljcr_oauth_fehler( 'unsupported_grant_type', 'Nur authorization_code und refresh_token.' );
}

function vljcr_oauth_revoke( WP_REST_Request $r ) {
	$t = vljcr_tokens_laden();
	$h = vljcr_hash( (string) $r->get_param( 'token' ) );
	if ( isset( $t[ $h ] ) ) {
		unset( $t[ $h ] );
		update_option( 'vljcr_tokens', $t, false );
	}
	return vljcr_json( new stdClass() );
}

/** Prüft das Zugangstoken eines MCP-Aufrufs. Liefert die Token-Daten oder null. */
function vljcr_token_pruefen() {
	if ( ! preg_match( '/^Bearer\s+(\S+)$/i', vljcr_auth_kopf(), $m ) ) {
		return null;
	}
	$t = vljcr_tokens_laden();
	$h = vljcr_hash( $m[1] );
	if ( ! isset( $t[ $h ] ) || 'access' !== $t[ $h ]['typ'] ) {
		return false;
	}
	if ( ! vljcr_konto_erlaubt( $t[ $h ]['oid'], $t[ $h ]['mail'] ) ) {
		return false;
	}
	return $t[ $h ];
}

/* ------------------------------------------------------------------ */
/* MCP                                                                 */
/* ------------------------------------------------------------------ */

function vljcr_rest( WP_REST_Request $request ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	$token = vljcr_token_pruefen();
	if ( ! $token || ! get_option( 'vljcr_aktiv' ) ) {
		$res = new WP_REST_Response( array( 'error' => false === $token ? 'invalid_token' : 'unauthorized', 'error_description' => 'Anmeldung erforderlich.' ), 401 );
		$kopf = 'Bearer resource_metadata="' . vljcr_prm_url() . '", scope="' . VLJCR_SCOPE . '"';
		if ( false === $token ) {
			$kopf .= ', error="invalid_token"';
		}
		$res->header( 'WWW-Authenticate', $kopf );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
	if ( 'POST' !== $request->get_method() ) {
		$res = new WP_REST_Response( null, 405 );
		$res->header( 'Allow', 'POST' );
		return $res;
	}

	$body = $request->get_json_params();
	if ( null === $body ) {
		$body = json_decode( (string) $request->get_body(), true );
	}
	if ( ! is_array( $body ) ) {
		return vljcr_json( vljcr_rpc_fehler( null, -32700, 'Parse error' ) );
	}

	$zugang    = 'redaktion';
	$ist_liste = array_keys( $body ) === range( 0, count( $body ) - 1 );
	$anfragen  = $ist_liste ? $body : array( $body );
	$antworten = array();
	foreach ( $anfragen as $a ) {
		$r = vljcr_rpc( is_array( $a ) ? $a : array(), $zugang );
		if ( null !== $r ) {
			$antworten[] = $r;
		}
	}
	if ( ! $antworten ) {
		status_header( 202 );
		header( 'Content-Length: 0' );
		header( 'Cache-Control: no-store, private' );
		exit;
	}
	return vljcr_json( $ist_liste ? $antworten : $antworten[0] );
}

function vljcr_rpc_fehler( $id, $code, $msg ) {
	return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $msg ) );
}

function vljcr_rpc( array $a, $zugang ) {
	$id     = array_key_exists( 'id', $a ) ? $a['id'] : null;
	$method = isset( $a['method'] ) ? (string) $a['method'] : '';
	$params = isset( $a['params'] ) && is_array( $a['params'] ) ? $a['params'] : array();
	$ist_benachrichtigung = ! array_key_exists( 'id', $a );

	if ( ! $method ) {
		return $ist_benachrichtigung ? null : vljcr_rpc_fehler( $id, -32600, 'Invalid Request' );
	}
	if ( $ist_benachrichtigung ) {
		return null;
	}

	switch ( $method ) {
		case 'initialize':
			$gewuenscht = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
			$bekannt    = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );
			$version    = in_array( $gewuenscht, $bekannt, true ) ? $gewuenscht : '2025-06-18';
			$result     = array(
				'protocolVersion' => $version,
				'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
				'serverInfo'      => array( 'name' => 'vlj-claude-redaktion', 'version' => VLJCR_VERSION ),
				'instructions'    => vljcr_hinweise( $zugang ),
			);
			break;
		case 'ping':
			$result = new stdClass();
			break;
		case 'tools/list':
			$result = array( 'tools' => vljcr_werkzeuge( $zugang ) );
			break;
		case 'tools/call':
			$result = vljcr_werkzeug_aufrufen( $params, $zugang );
			break;
		case 'resources/list':
			$result = array( 'resources' => array() );
			break;
		case 'prompts/list':
			$result = array( 'prompts' => array() );
			break;
		default:
			return vljcr_rpc_fehler( $id, -32601, 'Method not found: ' . $method );
	}
	return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
}

function vljcr_hinweise( $zugang ) {
	return 'Website ' . get_bloginfo( 'name' ) . ' (' . home_url( '/' ) . '), WordPress mit Block-Editor (Gutenberg). '
		. 'Inhalte sind Block-Markup (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->); beim Ändern das vorhandene Markup erhalten und nur den Text darin anpassen, für kleine Änderungen text_ersetzen nutzen. '
		. 'Vor jeder Änderung legt das Plugin eine Sicherung an (zuruecksetzen stellt sie wieder her); zusätzlich gibt es die normalen WordPress-Revisionen. '
		. 'Änderungen und Veröffentlichungen nur nach ausdrücklicher Freigabe der Person im Chat; den Text vorher zeigen. Das Feld freigabe nennt, wer wann freigegeben hat. '
		. 'Veröffentlichte Seiten lassen sich nach einer Änderung über ihren Link prüfen.';
}

/* ------------------------------------------------------------------ */
/* Werkzeug-Beschreibungen                                             */
/* ------------------------------------------------------------------ */

function vljcr_schema( $props, $required = array() ) {
	$s = array( 'type' => 'object', 'properties' => $props ? $props : new stdClass(), 'additionalProperties' => false );
	if ( $required ) {
		$s['required'] = $required;
	}
	return $s;
}

function vljcr_werkzeuge( $zugang ) {
	$freigabe = array( 'type' => 'string', 'description' => "Wer hat wann freigegeben, z. B. 'Joris 14.11.2026'" );
	$id       = array( 'type' => 'integer', 'description' => 'ID der Seite oder des Beitrags (aus inhalte_liste)' );

	$lesen = array(
		array(
			'name'        => 'website_info',
			'description' => 'Grunddaten der Website: Name, Adresse, Startseite, Inhaltstypen, Menüs mit Positionen, Theme, ausführender Benutzer.',
			'inputSchema' => vljcr_schema( array() ),
			'annotations' => array( 'readOnlyHint' => true ),
		),
		array(
			'name'        => 'inhalte_liste',
			'description' => 'Listet Seiten und Beiträge (ID, Typ, Status, Titel, Datum, Link, Eltern-Seite). Filter: typ (page, post oder any, Standard any), suche, status (any, publish, draft, pending, private, future, trash; Standard any ohne Papierkorb), anzahl (1–100, Standard 50), seite.',
			'inputSchema' => vljcr_schema(
				array(
					'typ'    => array( 'type' => 'string' ),
					'suche'  => array( 'type' => 'string' ),
					'status' => array( 'type' => 'string' ),
					'anzahl' => array( 'type' => 'integer' ),
					'seite'  => array( 'type' => 'integer' ),
				)
			),
			'annotations' => array( 'readOnlyHint' => true ),
		),
		array(
			'name'        => 'inhalt_lesen',
			'description' => 'Liest eine Seite oder einen Beitrag vollständig: Titel, Status, Link, Auszug, Rohinhalt (Block-Markup), sichtbarer Text, Beitragsbild, Kategorien, Schlagwörter, Sicherungen. Lange Inhalte: max_zeichen (Standard 30000) und ab.',
			'inputSchema' => vljcr_schema(
				array(
					'id'          => $id,
					'max_zeichen' => array( 'type' => 'integer' ),
					'ab'          => array( 'type' => 'integer' ),
				),
				array( 'id' )
			),
			'annotations' => array( 'readOnlyHint' => true ),
		),
		array(
			'name'        => 'menues_lesen',
			'description' => 'Alle Menüs mit ihren Einträgen (Eintrag-ID, Titel, Adresse, verknüpfte Seite, Eltern-Eintrag, Reihenfolge) und den Menü-Positionen des Themes.',
			'inputSchema' => vljcr_schema( array() ),
			'annotations' => array( 'readOnlyHint' => true ),
		),
		array(
			'name'        => 'medien_liste',
			'description' => 'Listet Bilder und Dateien der Mediathek (ID, Titel, Adresse, Typ, Datum, Alternativtext). Filter: suche, anzahl (1–100, Standard 30), seite.',
			'inputSchema' => vljcr_schema(
				array(
					'suche'  => array( 'type' => 'string' ),
					'anzahl' => array( 'type' => 'integer' ),
					'seite'  => array( 'type' => 'integer' ),
				)
			),
			'annotations' => array( 'readOnlyHint' => true ),
		),
		array(
			'name'        => 'protokoll_lesen',
			'description' => 'Zeigt die letzten Aktionen dieses Konnektors (Zeit, Werkzeug, Inhalt, Freigabe, Ergebnis). anzahl Standard 30.',
			'inputSchema' => vljcr_schema( array( 'anzahl' => array( 'type' => 'integer' ) ) ),
			'annotations' => array( 'readOnlyHint' => true ),
		),
		array(
			'name'        => 'entwurf_anlegen',
			'description' => 'Legt eine neue Seite oder einen neuen Beitrag als ENTWURF an (nicht öffentlich). typ page oder post (Standard post), titel, inhalt (Block-Markup oder einfaches HTML), auszug, eltern_id (nur Seiten), slug. Liefert ID, Bearbeiten-Link und Vorschau-Link.',
			'inputSchema' => vljcr_schema(
				array(
					'typ'       => array( 'type' => 'string', 'enum' => array( 'post', 'page' ) ),
					'titel'     => array( 'type' => 'string' ),
					'inhalt'    => array( 'type' => 'string' ),
					'auszug'    => array( 'type' => 'string' ),
					'eltern_id' => array( 'type' => 'integer' ),
					'slug'      => array( 'type' => 'string' ),
				),
				array( 'titel' )
			),
		),
	);

	if ( 'redaktion' !== $zugang ) {
		return $lesen;
	}

	$schreiben = array(
		array(
			'name'        => 'inhalt_aendern',
			'description' => 'ÄNDERT eine bestehende Seite oder einen Beitrag: titel, inhalt (vollständiges Block-Markup, ersetzt den ganzen Inhalt), auszug, slug, eltern_id, reihenfolge. Nicht übergebene Felder bleiben. Vorher Sicherung. Nur nach ausdrücklicher Freigabe.',
			'inputSchema' => vljcr_schema(
				array(
					'id'          => $id,
					'titel'       => array( 'type' => 'string' ),
					'inhalt'      => array( 'type' => 'string' ),
					'auszug'      => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'eltern_id'   => array( 'type' => 'integer' ),
					'reihenfolge' => array( 'type' => 'integer' ),
					'freigabe'    => $freigabe,
				),
				array( 'id', 'freigabe' )
			),
			'annotations' => array( 'destructiveHint' => true ),
		),
		array(
			'name'        => 'text_ersetzen',
			'description' => 'Ersetzt eine Textstelle in Titel, Inhalt (Rohinhalt inkl. Markup) und Auszug – und bei Seiten aus einem Baukasten (Brizy) zusätzlich in dessen Daten (Editor-Daten und fertiges HTML, auch base64/JSON-verpackt), damit die Änderung sichtbar wird und beim nächsten Bearbeiten im Baukasten erhalten bleibt. suchen muss je Fundort genau einmal vorkommen, außer alle = true. probelauf = true zeigt nur die Fundorte und ändert nichts (vor jeder Änderung an Baukasten-Seiten empfohlen). Vorher Sicherung (inkl. Baukasten-Daten). Nur nach ausdrücklicher Freigabe.',
			'inputSchema' => vljcr_schema(
				array(
					'id'        => $id,
					'suchen'    => array( 'type' => 'string' ),
					'ersetzen'  => array( 'type' => 'string' ),
					'alle'      => array( 'type' => 'boolean' ),
					'probelauf' => array( 'type' => 'boolean' ),
					'freigabe'  => $freigabe,
				),
				array( 'id', 'suchen', 'ersetzen', 'freigabe' )
			),
			'annotations' => array( 'destructiveHint' => true ),
		),
		array(
			'name'        => 'status_setzen',
			'description' => 'Setzt den Status: publish (veröffentlichen), draft, pending, private oder trash (Papierkorb, wiederherstellbar mit status draft). Vorher Sicherung. Nur nach ausdrücklicher Freigabe.',
			'inputSchema' => vljcr_schema(
				array(
					'id'       => $id,
					'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'trash' ) ),
					'freigabe' => $freigabe,
				),
				array( 'id', 'status', 'freigabe' )
			),
			'annotations' => array( 'destructiveHint' => true ),
		),
		array(
			'name'        => 'bild_hochladen',
			'description' => 'Lädt ein Bild oder eine PDF in die Mediathek: entweder url (öffentliche Adresse der Datei, bis 15 MB) oder daten_base64 mit dateiname (nur kleine Dateien). titel, alt (Alternativtext), beitrag_id (optional: Datei diesem Inhalt zuordnen). Liefert Medien-ID und Adresse. Nur nach Freigabe.',
			'inputSchema' => vljcr_schema(
				array(
					'url'          => array( 'type' => 'string' ),
					'daten_base64' => array( 'type' => 'string' ),
					'dateiname'    => array( 'type' => 'string' ),
					'titel'        => array( 'type' => 'string' ),
					'alt'          => array( 'type' => 'string' ),
					'beitrag_id'   => array( 'type' => 'integer' ),
					'freigabe'     => $freigabe,
				),
				array( 'freigabe' )
			),
		),
		array(
			'name'        => 'beitragsbild_setzen',
			'description' => 'Setzt das Beitragsbild einer Seite oder eines Beitrags (medien_id aus bild_hochladen oder medien_liste; 0 entfernt es). Nur nach Freigabe.',
			'inputSchema' => vljcr_schema(
				array(
					'id'        => $id,
					'medien_id' => array( 'type' => 'integer' ),
					'freigabe'  => $freigabe,
				),
				array( 'id', 'medien_id', 'freigabe' )
			),
		),
		array(
			'name'        => 'zuruecksetzen',
			'description' => 'Stellt eine Sicherung wieder her (Titel, Inhalt, Auszug, Status, Slug, Eltern-Seite, Reihenfolge). sicherung = Schlüssel aus inhalt_lesen (Feld sicherungen); ohne sicherung die neueste. Der aktuelle Stand wird vorher selbst gesichert. Nur nach Freigabe.',
			'inputSchema' => vljcr_schema(
				array(
					'id'        => $id,
					'sicherung' => array( 'type' => 'string' ),
					'freigabe'  => $freigabe,
				),
				array( 'id', 'freigabe' )
			),
			'annotations' => array( 'destructiveHint' => true ),
		),
		array(
			'name'        => 'menue_aendern',
			'description' => 'Ändert ein Menü. aktion: hinzufuegen (menue = ID oder Name, titel, und entweder seite_id oder url; optional eltern_eintrag, position), aendern (eintrag_id, dazu titel, url, seite_id, eltern_eintrag, position), entfernen (eintrag_id). Einträge aus menues_lesen. Nur nach Freigabe.',
			'inputSchema' => vljcr_schema(
				array(
					'aktion'         => array( 'type' => 'string', 'enum' => array( 'hinzufuegen', 'aendern', 'entfernen' ) ),
					'menue'          => array( 'type' => 'string' ),
					'eintrag_id'     => array( 'type' => 'integer' ),
					'titel'          => array( 'type' => 'string' ),
					'url'            => array( 'type' => 'string' ),
					'seite_id'       => array( 'type' => 'integer' ),
					'eltern_eintrag' => array( 'type' => 'integer' ),
					'position'       => array( 'type' => 'integer' ),
					'freigabe'       => $freigabe,
				),
				array( 'aktion', 'freigabe' )
			),
			'annotations' => array( 'destructiveHint' => true ),
		),
	);

	$schreiben[] = array(
		'name'        => 'optionen_suchen',
		'description' => 'Sucht Einstellungen (wp_options) für Rechtstexte und Plugin-Inhalte: begriff im Namen und/oder text im Wert (z. B. ein Name aus dem Impressum). Liefert nur Namen, Größe und ob der Konnektor den Wert lesen/ändern darf (nur Rechtstext-Optionen wie eRecht24, nie Schlüssel, Token oder Passwörter).',
		'inputSchema' => vljcr_schema( array( 'begriff' => array( 'type' => 'string' ), 'text' => array( 'type' => 'string' ) ) ),
		'annotations' => array( 'readOnlyHint' => true ),
	);
	$schreiben[] = array(
		'name'        => 'option_lesen',
		'description' => 'Liest eine freigegebene Rechtstext-Option (z. B. von eRecht24) vollständig; verschachtelte, JSON- oder base64-Werte werden aufgeschlüsselt. max_zeichen Standard 30000.',
		'inputSchema' => vljcr_schema( array( 'name' => array( 'type' => 'string' ), 'max_zeichen' => array( 'type' => 'integer' ) ), array( 'name' ) ),
		'annotations' => array( 'readOnlyHint' => true ),
	);
	$schreiben[] = array(
		'name'        => 'option_text_ersetzen',
		'description' => 'Ersetzt Text in einer freigegebenen Rechtstext-Option (z. B. Impressum von eRecht24). Wie text_ersetzen: je Fundort genau einmal außer alle = true, probelauf = true ändert nichts. Vorher Sicherung (option_zuruecksetzen). Hinweis: Bei einer neuen Übertragung aus dem eRecht24-Konto wird der Text dort überschrieben. Nur nach Freigabe.',
		'inputSchema' => vljcr_schema(
			array(
				'name'      => array( 'type' => 'string' ),
				'suchen'    => array( 'type' => 'string' ),
				'ersetzen'  => array( 'type' => 'string' ),
				'alle'      => array( 'type' => 'boolean' ),
				'probelauf' => array( 'type' => 'boolean' ),
				'freigabe'  => $freigabe,
			),
			array( 'name', 'suchen', 'ersetzen', 'freigabe' )
		),
		'annotations' => array( 'destructiveHint' => true ),
	);
	$schreiben[] = array(
		'name'        => 'option_zuruecksetzen',
		'description' => 'Stellt eine Rechtstext-Option aus einer Sicherung von option_text_ersetzen wieder her (ohne sicherung: die neueste dieser Option). Nur nach Freigabe.',
		'inputSchema' => vljcr_schema( array( 'name' => array( 'type' => 'string' ), 'sicherung' => array( 'type' => 'string' ), 'freigabe' => $freigabe ), array( 'name', 'freigabe' ) ),
		'annotations' => array( 'destructiveHint' => true ),
	);
	$schreiben[] = array(
		'name'        => 'plugin_selbst_aktualisieren',
		'description' => 'Aktualisiert dieses Plugin (VLJ Claude Redaktion) auf das neueste Release aus github.com/' . VLJCR_REPO . ' (mit Prüfsumme), sofern es neuer ist; danach ist es wieder aktiv. nur_pruefen = true meldet nur die verfügbare Version. Nur nach Freigabe.',
		'inputSchema' => vljcr_schema( array( 'nur_pruefen' => array( 'type' => 'boolean' ), 'freigabe' => $freigabe ), array( 'freigabe' ) ),
	);

	return array_merge( $lesen, $schreiben );
}

/* ------------------------------------------------------------------ */
/* Werkzeuge ausführen                                                 */
/* ------------------------------------------------------------------ */

class VLJCR_Fehler extends Exception {}

function vljcr_text_ergebnis( $daten, $fehler = false ) {
	$text = is_string( $daten ) ? $daten : wp_json_encode( $daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	$r    = array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ) );
	if ( $fehler ) {
		$r['isError'] = true;
	}
	return $r;
}

function vljcr_werkzeug_aufrufen( array $params, $zugang ) {
	$name = isset( $params['name'] ) ? (string) $params['name'] : '';
	$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

	$erlaubt = wp_list_pluck( vljcr_werkzeuge( $zugang ), 'name' );
	if ( ! in_array( $name, $erlaubt, true ) ) {
		$alle = wp_list_pluck( vljcr_werkzeuge( 'redaktion' ), 'name' );
		$msg  = in_array( $name, $alle, true )
			? 'Dieses Werkzeug ist über diesen Zugang gesperrt (nur Lesen und Entwürfe). Ändern geht nur über den Zugang "Redaktion".'
			: 'Unbekanntes Werkzeug: ' . $name;
		return vljcr_text_ergebnis( $msg, true );
	}

	$uid = (int) get_option( 'vljcr_user' );
	$u   = $uid ? get_userdata( $uid ) : false;
	if ( ! $u ) {
		return vljcr_text_ergebnis( 'Kein ausführender Benutzer eingestellt (WordPress > Werkzeuge > Claude Redaktion).', true );
	}
	wp_set_current_user( $uid );
	if ( ! user_can( $u, 'edit_pages' ) || ! user_can( $u, 'edit_posts' ) ) {
		return vljcr_text_ergebnis( 'Der ausführende Benutzer darf keine Seiten bearbeiten. Bitte in den Einstellungen einen Redakteur oder Administrator wählen.', true );
	}

	$schreibend = ! in_array( $name, array( 'website_info', 'inhalte_liste', 'inhalt_lesen', 'menues_lesen', 'medien_liste', 'protokoll_lesen', 'optionen_suchen', 'option_lesen' ), true );
	if ( $schreibend && 'entwurf_anlegen' !== $name ) {
		$fg = isset( $args['freigabe'] ) ? trim( (string) $args['freigabe'] ) : '';
		if ( '' === $fg ) {
			return vljcr_text_ergebnis( 'Feld freigabe fehlt: Wer hat wann freigegeben?', true );
		}
	}

	try {
		$fn = 'vljcr_w_' . $name;
		$r  = $fn( $args );
		if ( $schreibend ) {
			vljcr_protokoll( $name, $zugang, $args, is_string( $r ) ? $r : ( isset( $r['meldung'] ) ? $r['meldung'] : 'ok' ) );
		}
		return vljcr_text_ergebnis( $r );
	} catch ( VLJCR_Fehler $e ) {
		if ( $schreibend ) {
			vljcr_protokoll( $name, $zugang, $args, $e->getMessage(), true );
		}
		return vljcr_text_ergebnis( $e->getMessage(), true );
	} catch ( Throwable $e ) {
		if ( $schreibend ) {
			vljcr_protokoll( $name, $zugang, $args, 'Fehler: ' . $e->getMessage(), true );
		}
		return vljcr_text_ergebnis( 'Interner Fehler: ' . $e->getMessage(), true );
	}
}

function vljcr_typen() {
	return array( 'post', 'page' );
}

function vljcr_post_holen( $id, $auch_papierkorb = true ) {
	$p = get_post( (int) $id );
	if ( ! $p || ! in_array( $p->post_type, vljcr_typen(), true ) ) {
		throw new VLJCR_Fehler( 'Kein Beitrag und keine Seite mit ID ' . (int) $id . '.' );
	}
	if ( ! $auch_papierkorb && 'trash' === $p->post_status ) {
		throw new VLJCR_Fehler( 'Inhalt ' . $p->ID . ' liegt im Papierkorb. Erst mit status_setzen (draft) wiederherstellen.' );
	}
	if ( ! current_user_can( 'edit_post', $p->ID ) ) {
		throw new VLJCR_Fehler( 'Der ausführende Benutzer darf Inhalt ' . $p->ID . ' nicht bearbeiten.' );
	}
	return $p;
}

function vljcr_kurz( WP_Post $p ) {
	return array(
		'id'        => $p->ID,
		'typ'       => $p->post_type,
		'status'    => $p->post_status,
		'titel'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
		'slug'      => $p->post_name,
		'datum'     => $p->post_date,
		'geaendert' => $p->post_modified,
		'link'      => get_permalink( $p ),
		'eltern_id' => $p->post_parent,
	);
}

/* --- Sicherungen --- */

function vljcr_sicherung_anlegen( WP_Post $p, $grund ) {
	// Schlüssel sortieren chronologisch (Sekunde + Mikrosekunden), Zufallsteil nur gegen Kollisionen.
	$t    = microtime( true );
	$key  = '_vljcr_sicherung_' . gmdate( 'YmdHis', (int) $t ) . sprintf( '%06d', (int) ( ( $t - floor( $t ) ) * 1000000 ) ) . '_' . wp_generate_password( 4, false, false );
	$wert = array(
		'zeit'        => current_time( 'mysql' ),
		'grund'       => $grund,
		'titel'       => $p->post_title,
		'inhalt'      => $p->post_content,
		'auszug'      => $p->post_excerpt,
		'status'      => $p->post_status,
		'slug'        => $p->post_name,
		'eltern_id'   => $p->post_parent,
		'reihenfolge' => $p->menu_order,
		'bild'        => (int) get_post_thumbnail_id( $p ),
		'meta'        => vljcr_baukasten_meta( $p->ID ),
	);
	add_post_meta( $p->ID, $key, wp_slash( $wert ), true );

	$alle = vljcr_sicherungen_schluessel( $p->ID );
	if ( count( $alle ) > VLJCR_MAX_SICHERUNGEN ) {
		foreach ( array_slice( $alle, 0, count( $alle ) - VLJCR_MAX_SICHERUNGEN ) as $alt ) {
			delete_post_meta( $p->ID, $alt );
		}
	}
	return $key;
}

function vljcr_sicherungen_schluessel( $post_id ) {
	$keys = array();
	foreach ( array_keys( get_post_meta( $post_id ) ) as $k ) {
		if ( 0 === strpos( $k, '_vljcr_sicherung_' ) ) {
			$keys[] = $k;
		}
	}
	sort( $keys );
	return $keys;
}

function vljcr_aktualisieren( array $daten ) {
	$r = wp_update_post( wp_slash( $daten ), true );
	if ( is_wp_error( $r ) ) {
		throw new VLJCR_Fehler( 'WordPress meldet: ' . $r->get_error_message() );
	}
	clean_post_cache( (int) $daten['ID'] );
	return get_post( (int) $daten['ID'] );
}

/* --- Lesen --- */

function vljcr_w_website_info( $args ) {
	$typen = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $t ) {
		$typen[] = array( 'name' => $t->name, 'bezeichnung' => $t->label, 'anzahl' => (int) wp_count_posts( $t->name )->publish );
	}
	$menues = array();
	foreach ( wp_get_nav_menus() as $m ) {
		$menues[] = array( 'id' => $m->term_id, 'name' => $m->name, 'eintraege' => (int) $m->count );
	}
	$pos  = array();
	$reg  = get_registered_nav_menus();
	$orte = get_nav_menu_locations();
	foreach ( $reg as $slug => $label ) {
		$pos[] = array( 'position' => $slug, 'bezeichnung' => $label, 'menue_id' => isset( $orte[ $slug ] ) ? (int) $orte[ $slug ] : null );
	}
	$u = wp_get_current_user();
	return array(
		'name'                 => get_bloginfo( 'name' ),
		'untertitel'           => get_bloginfo( 'description' ),
		'adresse'              => home_url( '/' ),
		'wordpress'            => get_bloginfo( 'version' ),
		'theme'                => wp_get_theme()->get( 'Name' ),
		'block_theme'          => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
		'startseite'           => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 'neueste Beiträge',
		'beitragsseite'        => (int) get_option( 'page_for_posts' ),
		'inhaltstypen'         => $typen,
		'menues'               => $menues,
		'menue_positionen'     => $pos,
		'ausfuehrender_nutzer' => $u->user_login . ' (' . implode( ', ', $u->roles ) . ')',
		'plugin_version'       => VLJCR_VERSION,
	);
}

function vljcr_w_inhalte_liste( $args ) {
	$typ    = isset( $args['typ'] ) ? (string) $args['typ'] : 'any';
	$typ    = in_array( $typ, vljcr_typen(), true ) ? $typ : vljcr_typen();
	$status = isset( $args['status'] ) && '' !== $args['status'] ? (string) $args['status'] : 'any';
	$anzahl = isset( $args['anzahl'] ) ? max( 1, min( 100, (int) $args['anzahl'] ) ) : 50;
	$seite  = isset( $args['seite'] ) ? max( 1, (int) $args['seite'] ) : 1;
	$q      = new WP_Query(
		array(
			'post_type'      => $typ,
			'post_status'    => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
			's'              => isset( $args['suche'] ) ? (string) $args['suche'] : '',
			'posts_per_page' => $anzahl,
			'paged'          => $seite,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		)
	);
	return array(
		'gesamt'  => (int) $q->found_posts,
		'seite'   => $seite,
		'seiten'  => (int) $q->max_num_pages,
		'inhalte' => array_map( 'vljcr_kurz', $q->posts ),
	);
}

function vljcr_w_inhalt_lesen( $args ) {
	$p   = vljcr_post_holen( isset( $args['id'] ) ? $args['id'] : 0 );
	$max = isset( $args['max_zeichen'] ) ? max( 1000, (int) $args['max_zeichen'] ) : 30000;
	$ab  = isset( $args['ab'] ) ? max( 0, (int) $args['ab'] ) : 0;

	$roh      = (string) $p->post_content;
	$sichtbar = trim( preg_replace( "/\n{3,}/", "\n\n", wp_strip_all_tags( do_blocks( $roh ) ) ) );

	$sich = array();
	foreach ( array_reverse( vljcr_sicherungen_schluessel( $p->ID ) ) as $k ) {
		$v      = get_post_meta( $p->ID, $k, true );
		$sich[] = array( 'sicherung' => $k, 'zeit' => isset( $v['zeit'] ) ? $v['zeit'] : '', 'grund' => isset( $v['grund'] ) ? $v['grund'] : '' );
	}

	$bild_id = (int) get_post_thumbnail_id( $p );
	$out     = vljcr_kurz( $p );
	$out    += array(
		'reihenfolge'       => $p->menu_order,
		'vorlage'           => get_page_template_slug( $p ),
		'auszug'            => $p->post_excerpt,
		'beitragsbild'      => $bild_id ? array( 'id' => $bild_id, 'url' => wp_get_attachment_url( $bild_id ) ) : null,
		'kategorien'        => 'post' === $p->post_type ? wp_get_post_terms( $p->ID, 'category', array( 'fields' => 'names' ) ) : array(),
		'schlagwoerter'     => 'post' === $p->post_type ? wp_get_post_terms( $p->ID, 'post_tag', array( 'fields' => 'names' ) ) : array(),
		'inhalt_laenge'     => mb_strlen( $roh ),
		'inhalt_ab'         => $ab,
		'inhalt'            => mb_substr( $roh, $ab, $max ),
		'inhalt_vollstaendig' => ( $ab + $max ) >= mb_strlen( $roh ),
		'sichtbarer_text'   => mb_substr( $sichtbar, 0, 8000 ),
		'sicherungen'       => $sich,
		'bearbeiten'        => admin_url( 'post.php?post=' . $p->ID . '&action=edit' ),
		'baukasten'         => vljcr_baukasten_uebersicht( $p->ID ),
	);
	return $out;
}

function vljcr_w_menues_lesen( $args ) {
	$out = array();
	foreach ( wp_get_nav_menus() as $m ) {
		$items = wp_get_nav_menu_items( $m->term_id, array( 'post_status' => 'any' ) );
		$e     = array();
		foreach ( (array) $items as $i ) {
			$e[] = array(
				'eintrag_id'     => (int) $i->ID,
				'titel'          => $i->title,
				'url'            => $i->url,
				'art'            => $i->type,
				'objekt'         => $i->object,
				'objekt_id'      => (int) $i->object_id,
				'eltern_eintrag' => (int) $i->menu_item_parent,
				'position'       => (int) $i->menu_order,
			);
		}
		$out[] = array( 'id' => $m->term_id, 'name' => $m->name, 'eintraege' => $e );
	}
	$orte = array();
	foreach ( get_nav_menu_locations() as $ort => $mid ) {
		$orte[ $ort ] = (int) $mid;
	}
	return array( 'menues' => $out, 'positionen' => $orte );
}

function vljcr_w_medien_liste( $args ) {
	$anzahl = isset( $args['anzahl'] ) ? max( 1, min( 100, (int) $args['anzahl'] ) ) : 30;
	$seite  = isset( $args['seite'] ) ? max( 1, (int) $args['seite'] ) : 1;
	$q      = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => isset( $args['suche'] ) ? (string) $args['suche'] : '',
			'posts_per_page' => $anzahl,
			'paged'          => $seite,
		)
	);
	$m = array();
	foreach ( $q->posts as $a ) {
		$m[] = array(
			'id'    => $a->ID,
			'titel' => $a->post_title,
			'url'   => wp_get_attachment_url( $a->ID ),
			'typ'   => $a->post_mime_type,
			'datum' => $a->post_date,
			'alt'   => get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
		);
	}
	return array( 'gesamt' => (int) $q->found_posts, 'medien' => $m );
}

function vljcr_w_protokoll_lesen( $args ) {
	$n   = isset( $args['anzahl'] ) ? max( 1, min( 300, (int) $args['anzahl'] ) ) : 30;
	$log = get_option( 'vljcr_protokoll', array() );
	return array_reverse( array_slice( is_array( $log ) ? $log : array(), -$n ) );
}

/* --- Entwürfe --- */

function vljcr_w_entwurf_anlegen( $args ) {
	$typ = isset( $args['typ'] ) && 'page' === $args['typ'] ? 'page' : 'post';
	if ( ! current_user_can( 'page' === $typ ? 'edit_pages' : 'edit_posts' ) ) {
		throw new VLJCR_Fehler( 'Keine Berechtigung für diesen Inhaltstyp.' );
	}
	$daten = array(
		'post_type'    => $typ,
		'post_status'  => 'draft',
		'post_title'   => (string) $args['titel'],
		'post_content' => isset( $args['inhalt'] ) ? (string) $args['inhalt'] : '',
		'post_excerpt' => isset( $args['auszug'] ) ? (string) $args['auszug'] : '',
		'post_author'  => get_current_user_id(),
	);
	if ( 'page' === $typ && ! empty( $args['eltern_id'] ) ) {
		$daten['post_parent'] = (int) $args['eltern_id'];
	}
	if ( ! empty( $args['slug'] ) ) {
		$daten['post_name'] = sanitize_title( $args['slug'] );
	}
	$id = wp_insert_post( wp_slash( $daten ), true );
	if ( is_wp_error( $id ) ) {
		throw new VLJCR_Fehler( 'WordPress meldet: ' . $id->get_error_message() );
	}
	return array(
		'meldung'    => 'Entwurf ' . $id . ' angelegt.',
		'id'         => $id,
		'bearbeiten' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
		'vorschau'   => get_preview_post_link( $id ),
	);
}

/* --- Schreiben --- */

function vljcr_w_inhalt_aendern( $args ) {
	$p     = vljcr_post_holen( $args['id'], false );
	$daten = array( 'ID' => $p->ID );
	$felder = array(
		'titel'       => 'post_title',
		'inhalt'      => 'post_content',
		'auszug'      => 'post_excerpt',
		'slug'        => 'post_name',
		'eltern_id'   => 'post_parent',
		'reihenfolge' => 'menu_order',
	);
	foreach ( $felder as $k => $f ) {
		if ( array_key_exists( $k, $args ) && null !== $args[ $k ] ) {
			$daten[ $f ] = in_array( $k, array( 'eltern_id', 'reihenfolge' ), true ) ? (int) $args[ $k ] : (string) $args[ $k ];
		}
	}
	if ( 1 === count( $daten ) ) {
		throw new VLJCR_Fehler( 'Nichts zu ändern: titel, inhalt, auszug, slug, eltern_id oder reihenfolge angeben.' );
	}
	if ( isset( $daten['post_name'] ) ) {
		$daten['post_name'] = sanitize_title( $daten['post_name'] );
	}
	$key = vljcr_sicherung_anlegen( $p, 'vor inhalt_aendern' );
	$neu = vljcr_aktualisieren( $daten );
	return array(
		'meldung'   => 'Inhalt ' . $p->ID . ' geändert (' . implode( ', ', array_keys( array_diff_key( $daten, array( 'ID' => 1 ) ) ) ) . ').',
		'sicherung' => $key,
		'inhalt'    => vljcr_kurz( $neu ),
	);
}

/* --- Baukasten (Brizy u. ä.): Daten in Post-Meta --- */

/** Übersicht der Baukasten-Felder (nur Namen, Art, Größe), für inhalt_lesen. */
function vljcr_baukasten_uebersicht( $post_id ) {
	$meta = vljcr_baukasten_meta( $post_id );
	if ( ! $meta ) {
		return null;
	}
	$felder = array();
	$sammeln = function ( $wert, $pfad, $tiefe ) use ( &$sammeln, &$felder ) {
		if ( is_array( $wert ) ) {
			foreach ( $wert as $k => $v ) {
				$sammeln( $v, $pfad . '/' . $k, $tiefe );
			}
			return;
		}
		if ( ! is_string( $wert ) ) {
			$felder[ $pfad ] = gettype( $wert ) . ': ' . var_export( $wert, true );
			return;
		}
		$art = 'Text';
		if ( $tiefe < 3 && strlen( $wert ) >= 40 && preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $wert ) ) {
			$b = base64_decode( $wert, true );
			if ( false !== $b && mb_check_encoding( $b, 'UTF-8' ) ) {
				$art = 'base64 (' . ( is_array( json_decode( $b, true ) ) ? 'JSON' : ( false !== strpos( $b, '<' ) ? 'HTML' : 'Text' ) ) . ')';
			}
		} elseif ( is_array( json_decode( $wert, true ) ) ) {
			$art = 'JSON';
		} elseif ( false !== strpos( $wert, '<' ) ) {
			$art = 'HTML';
		}
		$felder[ $pfad ] = $art . ', ' . strlen( $wert ) . ' Zeichen' . ( strlen( $wert ) < 60 && 'Text' === $art ? ': ' . $wert : '' );
	};
	foreach ( $meta as $k => $v ) {
		$sammeln( $v, $k, 0 );
	}
	return array( 'art' => 'Brizy', 'hinweis' => 'Texte mit text_ersetzen (zuerst probelauf) ändern; neue Abschnitte im Brizy-Editor anlegen.', 'felder' => $felder );
}

/** Metafelder eines Baukastens (derzeit Brizy), Werte unserialisiert. */
function vljcr_baukasten_meta( $post_id ) {
	$out = array();
	foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $k ) {
		if ( preg_match( '/brizy/i', $k ) && 0 !== strpos( $k, '_vljcr_' ) ) {
			$out[ $k ] = get_post_meta( $post_id, $k, true );
		}
	}
	return $out;
}

/**
 * Ersetzt rekursiv in Arrays, JSON-Texten und base64-verpackten Texten.
 * $funde sammelt je Fundort (Pfad) die Anzahl. Gibt true zurück, wenn etwas ersetzt wurde.
 */
function vljcr_tief_ersetzen( &$wert, $suchen, $ersetzen, $pfad, array &$funde, $tiefe = 0 ) {
	if ( is_array( $wert ) ) {
		$geaendert = false;
		foreach ( $wert as $k => &$v ) {
			if ( vljcr_tief_ersetzen( $v, $suchen, $ersetzen, $pfad . '/' . $k, $funde, $tiefe ) ) {
				$geaendert = true;
			}
		}
		unset( $v );
		return $geaendert;
	}
	if ( ! is_string( $wert ) || '' === $wert ) {
		return false;
	}
	$anfang = ltrim( substr( $wert, 0, 20 ) );
	if ( $tiefe < 4 && strlen( $wert ) >= 2 && '' !== $anfang && ( '{' === $anfang[0] || '[' === $anfang[0] ) ) {
		$d = json_decode( $wert, true );
		if ( is_array( $d ) ) {
			if ( ! vljcr_tief_ersetzen( $d, $suchen, $ersetzen, $pfad . '{json}', $funde, $tiefe + 1 ) ) {
				return false;
			}
			$flags = 0;
			if ( false === strpos( $wert, '\\/' ) ) {
				$flags |= JSON_UNESCAPED_SLASHES;
			}
			if ( ! preg_match( '/\\\\u[0-9a-fA-F]{4}/', $wert ) ) {
				$flags |= JSON_UNESCAPED_UNICODE;
			}
			$neu = json_encode( $d, $flags );
			if ( false === $neu ) {
				throw new VLJCR_Fehler( 'JSON in ' . $pfad . ' ließ sich nicht wieder speichern; nichts geändert.' );
			}
			$wert = $neu;
			return true;
		}
	}
	if ( $tiefe < 4 && strlen( $wert ) >= 40 && preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $wert ) ) {
		$b = base64_decode( $wert, true );
		if ( false !== $b && mb_check_encoding( $b, 'UTF-8' ) && false !== strpbrk( $b, '<{[' ) ) {
			if ( ! vljcr_tief_ersetzen( $b, $suchen, $ersetzen, $pfad . '{base64}', $funde, $tiefe + 1 ) ) {
				return false;
			}
			$wert = base64_encode( $b );
			return true;
		}
	}
	$n = substr_count( $wert, $suchen );
	if ( ! $n ) {
		return false;
	}
	$funde[ $pfad ] = ( isset( $funde[ $pfad ] ) ? $funde[ $pfad ] : 0 ) + $n;
	$wert           = str_replace( $suchen, $ersetzen, $wert );
	return true;
}

function vljcr_w_text_ersetzen( $args ) {
	$p         = vljcr_post_holen( $args['id'], false );
	$suchen    = (string) $args['suchen'];
	$neu       = (string) $args['ersetzen'];
	$alle      = ! empty( $args['alle'] );
	$probelauf = ! empty( $args['probelauf'] );
	if ( '' === $suchen ) {
		throw new VLJCR_Fehler( 'suchen ist leer.' );
	}

	$funde  = array();
	$felder = array( 'post_title' => $p->post_title, 'post_content' => $p->post_content, 'post_excerpt' => $p->post_excerpt );
	$daten  = array( 'ID' => $p->ID );
	foreach ( $felder as $f => $v ) {
		$v = (string) $v;
		if ( vljcr_tief_ersetzen( $v, $suchen, $neu, $f, $funde, 4 ) ) {
			$daten[ $f ] = $v;
		}
	}
	$meta_alt = vljcr_baukasten_meta( $p->ID );
	$meta_neu = array();
	foreach ( $meta_alt as $k => $v ) {
		if ( vljcr_tief_ersetzen( $v, $suchen, $neu, 'meta:' . $k, $funde ) ) {
			$meta_neu[ $k ] = $v;
		}
	}

	$treffer = array_sum( $funde );
	if ( 0 === $treffer ) {
		throw new VLJCR_Fehler( 'Text nicht gefunden (Groß-/Kleinschreibung, Leerzeichen und HTML-Zeichen wie &amp; oder &nbsp; beachten; Rohinhalt mit inhalt_lesen prüfen).' );
	}
	$mehrfach = array_filter( $funde, function ( $n ) { return $n > 1; } );
	$bericht  = array(
		'treffer'          => $treffer,
		'fundorte'         => $funde,
		'baukasten'        => $meta_alt ? 'Brizy' : 'keiner',
		'baukasten_erfasst' => $meta_alt ? ( count( array_filter( array_keys( $funde ), function ( $k ) { return 0 === strpos( $k, 'meta:' ); } ) ) > 0 ) : null,
	);
	if ( $mehrfach && ! $alle ) {
		$bericht['meldung'] = 'Nicht geändert: Der Text kommt an einem Fundort mehrfach vor. Längeren, eindeutigen Ausschnitt wählen oder alle = true setzen.';
		if ( $probelauf ) {
			return $bericht;
		}
		throw new VLJCR_Fehler( $bericht['meldung'] . ' Fundorte: ' . wp_json_encode( $funde, JSON_UNESCAPED_UNICODE ) );
	}
	if ( $meta_alt && ! $bericht['baukasten_erfasst'] ) {
		$bericht['warnung'] = 'Die Seite nutzt einen Baukasten, der Text steht aber nicht in dessen Daten. Die Änderung ist auf der Website vermutlich nicht sichtbar.';
	}
	if ( $probelauf ) {
		$bericht['meldung'] = 'Probelauf: nichts geändert.';
		return $bericht;
	}

	$key = vljcr_sicherung_anlegen( $p, 'vor text_ersetzen' );
	foreach ( $meta_neu as $k => $v ) {
		update_post_meta( $p->ID, $k, wp_slash( $v ) );
	}
	// Auch ohne Änderung am Inhalt speichern, damit Caches und Hooks (Seiten-Cache) greifen.
	$n = vljcr_aktualisieren( $daten );
	if ( $meta_neu ) {
		vljcr_cache_leeren();
	}
	$bericht['meldung']   = $treffer . ' Stelle(n) in Inhalt ' . $p->ID . ' ersetzt' . ( $meta_neu ? ' (inkl. ' . count( $meta_neu ) . ' Baukasten-Feld(er))' : '' ) . '.';
	$bericht['sicherung'] = $key;
	$bericht['inhalt']    = vljcr_kurz( $n );
	return $bericht;
}

function vljcr_w_status_setzen( $args ) {
	$p      = vljcr_post_holen( $args['id'] );
	$status = (string) $args['status'];
	if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'trash' ), true ) ) {
		throw new VLJCR_Fehler( 'Unbekannter Status.' );
	}
	if ( 'publish' === $status && ! current_user_can( 'page' === $p->post_type ? 'publish_pages' : 'publish_posts' ) ) {
		throw new VLJCR_Fehler( 'Der ausführende Benutzer darf nicht veröffentlichen.' );
	}
	$key = vljcr_sicherung_anlegen( $p, 'vor status_setzen ' . $status );
	if ( 'trash' === $status ) {
		$r = wp_trash_post( $p->ID );
		if ( ! $r ) {
			throw new VLJCR_Fehler( 'Konnte nicht in den Papierkorb legen.' );
		}
		$n = get_post( $p->ID );
	} elseif ( 'trash' === $p->post_status ) {
		wp_untrash_post( $p->ID );
		$n = vljcr_aktualisieren( array( 'ID' => $p->ID, 'post_status' => $status ) );
	} else {
		$daten = array( 'ID' => $p->ID, 'post_status' => $status );
		if ( 'publish' === $status && in_array( $p->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			// Datum beim ersten Veröffentlichen auf jetzt setzen (sonst gilt das Entwurfsdatum).
			$daten['post_date']     = current_time( 'mysql' );
			$daten['post_date_gmt'] = current_time( 'mysql', true );
		}
		$n = vljcr_aktualisieren( $daten );
	}
	return array(
		'meldung'   => 'Status von ' . $p->ID . ': ' . $p->post_status . ' -> ' . $n->post_status . '.',
		'sicherung' => $key,
		'inhalt'    => vljcr_kurz( $n ),
	);
}

function vljcr_medien_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}

function vljcr_w_bild_hochladen( $args ) {
	if ( ! current_user_can( 'upload_files' ) ) {
		throw new VLJCR_Fehler( 'Der ausführende Benutzer darf keine Dateien hochladen.' );
	}
	vljcr_medien_includes();
	$erlaubt = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' );
	$tmp     = '';
	$name    = '';

	if ( ! empty( $args['url'] ) ) {
		$url = esc_url_raw( (string) $args['url'] );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			throw new VLJCR_Fehler( 'url muss mit http:// oder https:// beginnen.' );
		}
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			throw new VLJCR_Fehler( 'Download fehlgeschlagen: ' . $tmp->get_error_message() );
		}
		$name = ! empty( $args['dateiname'] ) ? (string) $args['dateiname'] : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	} elseif ( ! empty( $args['daten_base64'] ) ) {
		$bin = base64_decode( (string) $args['daten_base64'], true );
		if ( false === $bin ) {
			throw new VLJCR_Fehler( 'daten_base64 ist kein gültiges Base64.' );
		}
		$tmp = wp_tempnam( 'vljcr' );
		file_put_contents( $tmp, $bin );
		$name = ! empty( $args['dateiname'] ) ? (string) $args['dateiname'] : 'bild.jpg';
	} else {
		throw new VLJCR_Fehler( 'url oder daten_base64 angeben.' );
	}

	if ( filesize( $tmp ) > 15 * 1024 * 1024 ) {
		@unlink( $tmp );
		throw new VLJCR_Fehler( 'Datei größer als 15 MB.' );
	}
	// Typ anhand des Inhalts bestimmen und Endung passend setzen.
	$check = wp_check_filetype_and_ext( $tmp, $name );
	$ext   = $check['ext'] ? $check['ext'] : strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	if ( ! $check['ext'] ) {
		$info = function_exists( 'getimagesize' ) ? @getimagesize( $tmp ) : false;
		$map  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' );
		if ( $info && isset( $map[ $info['mime'] ] ) ) {
			$ext = $map[ $info['mime'] ];
		}
	}
	if ( ! in_array( $ext, $erlaubt, true ) ) {
		@unlink( $tmp );
		throw new VLJCR_Fehler( 'Nur Bilder (jpg, png, gif, webp) und PDF sind erlaubt.' );
	}
	$basis = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
	$basis = $basis ? $basis : 'datei';
	$datei = array( 'name' => $basis . '.' . $ext, 'tmp_name' => $tmp );

	$post_id = ! empty( $args['beitrag_id'] ) ? (int) $args['beitrag_id'] : 0;
	$mid     = media_handle_sideload( $datei, $post_id, ! empty( $args['titel'] ) ? (string) $args['titel'] : null );
	if ( is_wp_error( $mid ) ) {
		@unlink( $tmp );
		throw new VLJCR_Fehler( 'Hochladen fehlgeschlagen: ' . $mid->get_error_message() );
	}
	if ( ! empty( $args['alt'] ) ) {
		update_post_meta( $mid, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
	}
	return array(
		'meldung'   => 'Datei hochgeladen (Medien-ID ' . $mid . ').',
		'medien_id' => $mid,
		'url'       => wp_get_attachment_url( $mid ),
		'typ'       => get_post_mime_type( $mid ),
	);
}

function vljcr_w_beitragsbild_setzen( $args ) {
	$p   = vljcr_post_holen( $args['id'], false );
	$mid = (int) $args['medien_id'];
	vljcr_sicherung_anlegen( $p, 'vor beitragsbild_setzen' );
	if ( 0 === $mid ) {
		delete_post_thumbnail( $p->ID );
		return array( 'meldung' => 'Beitragsbild von ' . $p->ID . ' entfernt.' );
	}
	$a = get_post( $mid );
	if ( ! $a || 'attachment' !== $a->post_type || ! wp_attachment_is_image( $mid ) ) {
		throw new VLJCR_Fehler( 'Medien-ID ' . $mid . ' ist kein Bild.' );
	}
	set_post_thumbnail( $p->ID, $mid );
	return array( 'meldung' => 'Beitragsbild von ' . $p->ID . ' gesetzt (Medien-ID ' . $mid . ').', 'url' => wp_get_attachment_url( $mid ) );
}

function vljcr_w_zuruecksetzen( $args ) {
	$p    = vljcr_post_holen( $args['id'] );
	$alle = vljcr_sicherungen_schluessel( $p->ID );
	if ( ! $alle ) {
		throw new VLJCR_Fehler( 'Für Inhalt ' . $p->ID . ' gibt es keine Sicherung.' );
	}
	$key = ! empty( $args['sicherung'] ) ? (string) $args['sicherung'] : end( $alle );
	if ( ! in_array( $key, $alle, true ) ) {
		throw new VLJCR_Fehler( 'Sicherung ' . $key . ' gibt es nicht. Vorhanden: ' . implode( ', ', $alle ) );
	}
	$s = get_post_meta( $p->ID, $key, true );
	if ( ! is_array( $s ) ) {
		throw new VLJCR_Fehler( 'Sicherung ist beschädigt.' );
	}
	$vorher = vljcr_sicherung_anlegen( $p, 'vor zuruecksetzen auf ' . $key );
	if ( 'trash' === $p->post_status ) {
		wp_untrash_post( $p->ID );
	}
	$n = vljcr_aktualisieren(
		array(
			'ID'           => $p->ID,
			'post_title'   => $s['titel'],
			'post_content' => $s['inhalt'],
			'post_excerpt' => $s['auszug'],
			'post_status'  => 'trash' === $s['status'] ? 'draft' : $s['status'],
			'post_name'    => $s['slug'],
			'post_parent'  => (int) $s['eltern_id'],
			'menu_order'   => (int) $s['reihenfolge'],
		)
	);
	if ( isset( $s['bild'] ) ) {
		if ( (int) $s['bild'] ) {
			set_post_thumbnail( $p->ID, (int) $s['bild'] );
		} else {
			delete_post_thumbnail( $p->ID );
		}
	}
	if ( ! empty( $s['meta'] ) && is_array( $s['meta'] ) ) {
		foreach ( $s['meta'] as $mk => $mv ) {
			update_post_meta( $p->ID, $mk, wp_slash( $mv ) );
		}
		vljcr_cache_leeren();
	}
	return array(
		'meldung'          => 'Inhalt ' . $p->ID . ' auf Sicherung ' . $key . ' (' . $s['zeit'] . ') zurückgesetzt' . ( ! empty( $s['meta'] ) ? ' (inkl. Baukasten-Daten)' : '' ) . '.',
		'sicherung_vorher' => $vorher,
		'inhalt'           => vljcr_kurz( $n ),
	);
}

/* --- Rechtstext-Optionen (eRecht24 u. ä.) --- */

function vljcr_option_erlaubt( $name ) {
	$name = (string) $name;
	if ( preg_match( '/(key|token|secret|pass|api|auth|nonce|salt|hash|cred|licen)/i', $name ) ) {
		return false;
	}
	$muster = apply_filters( 'vljcr_option_muster', '/^(erecht24|_erecht24|e_recht24|rechtstext|legal_text)|(imprint|impressum|privacy_policy_text|datenschutz)/i' );
	return (bool) preg_match( $muster, $name );
}

function vljcr_w_optionen_suchen( $args ) {
	global $wpdb;
	$begriff = isset( $args['begriff'] ) ? trim( (string) $args['begriff'] ) : '';
	$text    = isset( $args['text'] ) ? trim( (string) $args['text'] ) : '';
	if ( mb_strlen( $begriff ) < 3 && mb_strlen( $text ) < 3 ) {
		throw new VLJCR_Fehler( 'begriff oder text mit mindestens 3 Zeichen angeben.' );
	}
	$where = array();
	$werte = array();
	if ( '' !== $begriff ) {
		$where[] = 'option_name LIKE %s';
		$werte[] = '%' . $wpdb->esc_like( $begriff ) . '%';
	}
	if ( '' !== $text ) {
		$where[] = 'option_value LIKE %s';
		$werte[] = '%' . $wpdb->esc_like( $text ) . '%';
	}
	$sql   = "SELECT option_name, LENGTH(option_value) AS laenge, autoload FROM {$wpdb->options} WHERE " . implode( ' AND ', $where ) . ' ORDER BY option_name LIMIT 100';
	$zeilen = $wpdb->get_results( $wpdb->prepare( $sql, $werte ), ARRAY_A );
	$out    = array();
	foreach ( (array) $zeilen as $z ) {
		if ( 0 === strpos( $z['option_name'], '_transient' ) || 0 === strpos( $z['option_name'], '_site_transient' ) ) {
			continue;
		}
		$out[] = array( 'name' => $z['option_name'], 'zeichen' => (int) $z['laenge'], 'freigegeben' => vljcr_option_erlaubt( $z['option_name'] ) );
	}
	$hinweis = ( '' !== $text && ! $out ) ? 'Nicht in den Optionen gefunden. Der Text kommt dann vermutlich bei jedem Aufruf vom Anbieter (z. B. eRecht24-Server) oder steht in einer eigenen Tabelle.' : '';
	return array( 'treffer' => $out, 'hinweis' => $hinweis );
}

function vljcr_option_holen( $name ) {
	$name = (string) $name;
	if ( ! vljcr_option_erlaubt( $name ) ) {
		throw new VLJCR_Fehler( 'Diese Option ist für den Konnektor nicht freigegeben (nur Rechtstexte, keine Schlüssel).' );
	}
	$wert = get_option( $name, null );
	if ( null === $wert ) {
		throw new VLJCR_Fehler( 'Option ' . $name . ' gibt es nicht.' );
	}
	return $wert;
}

function vljcr_aufschluesseln( $wert, $tiefe = 0 ) {
	if ( is_array( $wert ) ) {
		$o = array();
		foreach ( $wert as $k => $v ) {
			$o[ $k ] = vljcr_aufschluesseln( $v, $tiefe );
		}
		return $o;
	}
	if ( is_string( $wert ) && $tiefe < 3 ) {
		$a = ltrim( substr( $wert, 0, 5 ) );
		if ( '' !== $a && ( '{' === $a[0] || '[' === $a[0] ) && is_array( json_decode( $wert, true ) ) ) {
			return array( '{json}' => vljcr_aufschluesseln( json_decode( $wert, true ), $tiefe + 1 ) );
		}
		if ( strlen( $wert ) >= 40 && preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $wert ) ) {
			$b = base64_decode( $wert, true );
			if ( false !== $b && mb_check_encoding( $b, 'UTF-8' ) ) {
				return array( '{base64}' => vljcr_aufschluesseln( $b, $tiefe + 1 ) );
			}
		}
	}
	return $wert;
}

function vljcr_w_option_lesen( $args ) {
	$wert = vljcr_option_holen( $args['name'] );
	$max  = isset( $args['max_zeichen'] ) ? max( 1000, (int) $args['max_zeichen'] ) : 30000;
	$text = wp_json_encode( vljcr_aufschluesseln( $wert ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	return array( 'name' => (string) $args['name'], 'zeichen' => mb_strlen( $text ), 'wert' => mb_substr( $text, 0, $max ), 'vollstaendig' => mb_strlen( $text ) <= $max );
}

function vljcr_w_option_text_ersetzen( $args ) {
	$name   = (string) $args['name'];
	$wert   = vljcr_option_holen( $name );
	$suchen = (string) $args['suchen'];
	if ( '' === $suchen ) {
		throw new VLJCR_Fehler( 'suchen ist leer.' );
	}
	$neu   = $wert;
	$funde = array();
	vljcr_tief_ersetzen( $neu, $suchen, (string) $args['ersetzen'], $name, $funde );
	$treffer = array_sum( $funde );
	if ( ! $treffer ) {
		throw new VLJCR_Fehler( 'Text nicht gefunden (HTML-Zeichen wie &amp; oder &nbsp; beachten; mit option_lesen prüfen).' );
	}
	$mehrfach = array_filter( $funde, function ( $n ) { return $n > 1; } );
	$bericht  = array( 'treffer' => $treffer, 'fundorte' => $funde );
	if ( $mehrfach && empty( $args['alle'] ) ) {
		$bericht['meldung'] = 'Nicht geändert: Der Text kommt an einem Fundort mehrfach vor. Längeren Ausschnitt wählen oder alle = true.';
		if ( ! empty( $args['probelauf'] ) ) {
			return $bericht;
		}
		throw new VLJCR_Fehler( $bericht['meldung'] );
	}
	if ( ! empty( $args['probelauf'] ) ) {
		$bericht['meldung'] = 'Probelauf: nichts geändert.';
		return $bericht;
	}
	$sich   = (array) get_option( 'vljcr_option_sicherungen', array() );
	$schl   = $name . '@' . gmdate( 'YmdHis' ) . '_' . wp_generate_password( 4, false, false );
	$sich[ $schl ] = array( 'name' => $name, 'zeit' => current_time( 'mysql' ), 'wert' => $wert );
	if ( count( $sich ) > 30 ) {
		$sich = array_slice( $sich, -30, null, true );
	}
	update_option( 'vljcr_option_sicherungen', $sich, false );
	update_option( $name, $neu );
	vljcr_cache_leeren();
	$bericht['meldung']   = $treffer . ' Stelle(n) in Option ' . $name . ' ersetzt.';
	$bericht['sicherung'] = $schl;
	return $bericht;
}

function vljcr_w_option_zuruecksetzen( $args ) {
	$name = (string) $args['name'];
	vljcr_option_holen( $name );
	$sich = (array) get_option( 'vljcr_option_sicherungen', array() );
	$eigene = array_filter( $sich, function ( $s ) use ( $name ) { return isset( $s['name'] ) && $s['name'] === $name; } );
	if ( ! $eigene ) {
		throw new VLJCR_Fehler( 'Keine Sicherung für ' . $name . '.' );
	}
	$schl = ! empty( $args['sicherung'] ) ? (string) $args['sicherung'] : array_key_last( $eigene );
	if ( ! isset( $eigene[ $schl ] ) ) {
		throw new VLJCR_Fehler( 'Sicherung gibt es nicht. Vorhanden: ' . implode( ', ', array_keys( $eigene ) ) );
	}
	update_option( $name, $eigene[ $schl ]['wert'] );
	vljcr_cache_leeren();
	return array( 'meldung' => 'Option ' . $name . ' auf Stand ' . $eigene[ $schl ]['zeit'] . ' zurückgesetzt.' );
}

/* --- Selbst-Update --- */

function vljcr_w_plugin_selbst_aktualisieren( $args ) {
	if ( ! current_user_can( 'update_plugins' ) ) {
		throw new VLJCR_Fehler( 'Der ausführende Benutzer darf keine Plugins aktualisieren.' );
	}
	delete_site_transient( 'vljcr_release' );
	$r = vljcr_release();
	if ( ! $r ) {
		throw new VLJCR_Fehler( 'Kein Release auf GitHub gefunden oder GitHub nicht erreichbar.' );
	}
	if ( ! version_compare( $r['version'], VLJCR_VERSION, '>' ) ) {
		return array( 'meldung' => 'Schon aktuell: ' . VLJCR_VERSION . ' (neuestes Release ' . $r['version'] . ').' );
	}
	if ( ! empty( $args['nur_pruefen'] ) ) {
		return array( 'meldung' => 'Verfügbar: ' . $r['version'] . ' (installiert ' . VLJCR_VERSION . ').' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$ich = plugin_basename( __FILE__ );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$skin = new Automatic_Upgrader_Skin();
	$up   = new Plugin_Upgrader( $skin );
	$erg  = $up->upgrade( $ich );
	// Wieder aktivieren (WordPress deaktiviert außerhalb des Cron vor dem Update). Die neue Datei wird erst beim nächsten Aufruf geladen.
	if ( ! is_plugin_active( $ich ) ) {
		$aktive   = (array) get_option( 'active_plugins', array() );
		$aktive[] = $ich;
		update_option( 'active_plugins', array_values( array_unique( $aktive ) ) );
	}
	$texte = array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() );
	if ( true !== $erg ) {
		throw new VLJCR_Fehler( 'Update fehlgeschlagen: ' . ( is_wp_error( $erg ) ? $erg->get_error_message() : implode( ' / ', $texte ) ) );
	}
	$neu = get_file_data( WP_PLUGIN_DIR . '/' . $ich, array( 'v' => 'Version' ) );
	return array( 'meldung' => 'Aktualisiert von ' . VLJCR_VERSION . ' auf ' . $neu['v'] . '.', 'schritte' => $texte );
}

function vljcr_menue_finden( $m ) {
	$m = trim( (string) $m );
	if ( '' === $m ) {
		throw new VLJCR_Fehler( 'menue angeben (ID oder Name, siehe menues_lesen).' );
	}
	$obj = ctype_digit( $m ) ? wp_get_nav_menu_object( (int) $m ) : wp_get_nav_menu_object( $m );
	if ( ! $obj ) {
		throw new VLJCR_Fehler( 'Menü ' . $m . ' nicht gefunden.' );
	}
	return $obj;
}

function vljcr_cache_leeren() {
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	do_action( 'vljcr_cache_leeren' );
}

function vljcr_w_menue_aendern( $args ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		throw new VLJCR_Fehler( 'Der ausführende Benutzer darf Menüs nicht bearbeiten (Recht edit_theme_options, z. B. Administrator).' );
	}
	$aktion = (string) $args['aktion'];

	if ( 'hinzufuegen' === $aktion ) {
		$menu = vljcr_menue_finden( isset( $args['menue'] ) ? $args['menue'] : '' );
		$d    = array(
			'menu-item-title'     => isset( $args['titel'] ) ? (string) $args['titel'] : '',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => isset( $args['eltern_eintrag'] ) ? (int) $args['eltern_eintrag'] : 0,
			'menu-item-position'  => isset( $args['position'] ) ? (int) $args['position'] : 0,
		);
		if ( ! empty( $args['seite_id'] ) ) {
			$z = get_post( (int) $args['seite_id'] );
			if ( ! $z ) {
				throw new VLJCR_Fehler( 'Seite ' . (int) $args['seite_id'] . ' gibt es nicht.' );
			}
			$d += array( 'menu-item-object' => $z->post_type, 'menu-item-object-id' => $z->ID, 'menu-item-type' => 'post_type' );
		} elseif ( ! empty( $args['url'] ) ) {
			$d += array( 'menu-item-url' => esc_url_raw( (string) $args['url'] ), 'menu-item-type' => 'custom' );
			if ( '' === $d['menu-item-title'] ) {
				throw new VLJCR_Fehler( 'Für einen Link-Eintrag titel angeben.' );
			}
		} else {
			throw new VLJCR_Fehler( 'seite_id oder url angeben.' );
		}
		$r = wp_update_nav_menu_item( $menu->term_id, 0, wp_slash( $d ) );
		if ( is_wp_error( $r ) ) {
			throw new VLJCR_Fehler( 'WordPress meldet: ' . $r->get_error_message() );
		}
		vljcr_cache_leeren();
		return array( 'meldung' => 'Menüeintrag ' . $r . ' in "' . $menu->name . '" angelegt.', 'eintrag_id' => $r );
	}

	$eid  = isset( $args['eintrag_id'] ) ? (int) $args['eintrag_id'] : 0;
	$item = $eid ? get_post( $eid ) : null;
	if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
		throw new VLJCR_Fehler( 'eintrag_id fehlt oder ist kein Menüeintrag.' );
	}
	$menus = wp_get_object_terms( $eid, 'nav_menu' );
	if ( ! $menus || is_wp_error( $menus ) ) {
		throw new VLJCR_Fehler( 'Menü des Eintrags nicht gefunden.' );
	}
	$menu = $menus[0];

	if ( 'entfernen' === $aktion ) {
		$alt = wp_setup_nav_menu_item( $item );
		wp_delete_post( $eid, true );
		vljcr_cache_leeren();
		return array(
			'meldung'           => 'Menüeintrag ' . $eid . ' ("' . $alt->title . '") aus "' . $menu->name . '" entfernt.',
			'zum_wiederherstellen' => array( 'titel' => $alt->title, 'url' => $alt->url, 'seite_id' => 'custom' === $alt->type ? null : (int) $alt->object_id, 'eltern_eintrag' => (int) $alt->menu_item_parent, 'position' => (int) $alt->menu_order ),
		);
	}

	if ( 'aendern' === $aktion ) {
		$alt = wp_setup_nav_menu_item( $item );
		$d   = array(
			'menu-item-db-id'     => $eid,
			'menu-item-title'     => isset( $args['titel'] ) ? (string) $args['titel'] : $alt->title,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => isset( $args['eltern_eintrag'] ) ? (int) $args['eltern_eintrag'] : (int) $alt->menu_item_parent,
			'menu-item-position'  => isset( $args['position'] ) ? (int) $args['position'] : (int) $alt->menu_order,
			'menu-item-type'      => $alt->type,
			'menu-item-object'    => $alt->object,
			'menu-item-object-id' => (int) $alt->object_id,
			'menu-item-url'       => $alt->url,
			'menu-item-target'    => $alt->target,
			'menu-item-classes'   => implode( ' ', (array) $alt->classes ),
			'menu-item-xfn'       => $alt->xfn,
			'menu-item-description' => $alt->description,
			'menu-item-attr-title'  => $alt->attr_title,
		);
		if ( ! empty( $args['seite_id'] ) ) {
			$z = get_post( (int) $args['seite_id'] );
			if ( ! $z ) {
				throw new VLJCR_Fehler( 'Seite ' . (int) $args['seite_id'] . ' gibt es nicht.' );
			}
			$d['menu-item-type']      = 'post_type';
			$d['menu-item-object']    = $z->post_type;
			$d['menu-item-object-id'] = $z->ID;
			$d['menu-item-url']       = '';
		} elseif ( ! empty( $args['url'] ) ) {
			$d['menu-item-type']      = 'custom';
			$d['menu-item-object']    = 'custom';
			$d['menu-item-object-id'] = 0;
			$d['menu-item-url']       = esc_url_raw( (string) $args['url'] );
		}
		$r = wp_update_nav_menu_item( $menu->term_id, $eid, wp_slash( $d ) );
		if ( is_wp_error( $r ) ) {
			throw new VLJCR_Fehler( 'WordPress meldet: ' . $r->get_error_message() );
		}
		vljcr_cache_leeren();
		return array(
			'meldung' => 'Menüeintrag ' . $eid . ' geändert.',
			'vorher'  => array( 'titel' => $alt->title, 'url' => $alt->url, 'eltern_eintrag' => (int) $alt->menu_item_parent, 'position' => (int) $alt->menu_order ),
		);
	}

	throw new VLJCR_Fehler( 'Unbekannte aktion.' );
}

/* ------------------------------------------------------------------ */
/* Einstellungsseite (Werkzeuge > Claude Redaktion)                    */
/* ------------------------------------------------------------------ */

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Claude Redaktion', 'Claude Redaktion', 'manage_options', 'vlj-claude-redaktion', 'vljcr_seite' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=vlj-claude-redaktion' ) ) . '">Einstellungen</a>' );
		return $links;
	}
);

function vljcr_kopf_diagnose() {
	$r = wp_remote_get(
		vljcr_issuer() . '/diagnose/kopf',
		array( 'timeout' => 8, 'headers' => array( 'Authorization' => 'Bearer diagnose' ), 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) )
	);
	if ( is_wp_error( $r ) ) {
		return null;
	}
	$d = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	return is_array( $d ) && isset( $d['authorization_angekommen'] ) ? (bool) $d['authorization_angekommen'] : null;
}

function vljcr_seite() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$meldung = '';
	if ( isset( $_POST['vljcr_aktion'] ) && check_admin_referer( 'vljcr_einstellungen' ) ) {
		$aktion = sanitize_key( wp_unslash( $_POST['vljcr_aktion'] ) );
		if ( 'speichern' === $aktion ) {
			update_option( 'vljcr_aktiv', empty( $_POST['vljcr_aktiv'] ) ? 0 : 1, false );
			$uid = isset( $_POST['vljcr_user'] ) ? (int) $_POST['vljcr_user'] : 0;
			if ( $uid && user_can( $uid, 'edit_pages' ) ) {
				update_option( 'vljcr_user', $uid, false );
			}
			$mandant = isset( $_POST['vljcr_ms_mandant'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['vljcr_ms_mandant'] ) ) ) ) : '';
			if ( preg_match( '/^[0-9a-f-]{36}$/', $mandant ) ) {
				update_option( 'vljcr_ms_mandant', $mandant, false );
			}
			$cid = isset( $_POST['vljcr_ms_client_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['vljcr_ms_client_id'] ) ) ) : '';
			update_option( 'vljcr_ms_client_id', $cid, false );
			$sec = isset( $_POST['vljcr_ms_secret'] ) ? trim( (string) wp_unslash( $_POST['vljcr_ms_secret'] ) ) : '';
			if ( '' !== $sec ) {
				update_option( 'vljcr_ms_secret', $sec, false );
			}
			$konten = isset( $_POST['vljcr_konten'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vljcr_konten'] ) ) : '';
			update_option( 'vljcr_konten', $konten, false );
			$meldung = 'Gespeichert.';
		} elseif ( 'updates' === $aktion ) {
			delete_site_transient( 'vljcr_release' );
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			$r       = vljcr_release();
			$meldung = $r ? ( version_compare( $r['version'], VLJCR_VERSION, '>' ) ? 'Neue Version ' . $r['version'] . ' gefunden. Sie wird automatisch eingespielt oder unter Dashboard > Aktualisierungen sofort.' : 'Aktuell: ' . VLJCR_VERSION . ' ist die neueste Version.' ) : 'GitHub nicht erreichbar oder noch kein Release vorhanden.';
		} elseif ( 'widerrufen' === $aktion ) {
			update_option( 'vljcr_tokens', array(), false );
			update_option( 'vljcr_clients', array(), false );
			vljcr_protokoll( 'widerrufen', 'admin', array(), 'Alle Anmeldungen widerrufen von ' . wp_get_current_user()->user_login );
			$meldung = 'Alle Anmeldungen widerrufen. Claude muss sich neu verbinden.';
		}
	}
	vljcr_aktivieren();

	$aktiv   = (int) get_option( 'vljcr_aktiv' );
	$uid     = (int) get_option( 'vljcr_user' );
	$users   = get_users( array( 'capability' => 'edit_pages', 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
	$log     = array_reverse( array_slice( (array) get_option( 'vljcr_protokoll', array() ), -50 ) );
	$fertig  = get_option( 'vljcr_ms_client_id' ) && get_option( 'vljcr_ms_secret' );
	$tokens  = vljcr_tokens_laden();
	$angem   = array();
	foreach ( $tokens as $t ) {
		if ( 'refresh' === $t['typ'] ) {
			$angem[] = $t['mail'] . ' (bis ' . wp_date( 'd.m.Y', $t['exp'] ) . ')';
		}
	}
	$kopf = isset( $_GET['diagnose'] ) ? vljcr_kopf_diagnose() : 'nicht geprüft';
	?>
	<div class="wrap">
		<h1>Claude Redaktion</h1>
		<?php if ( $meldung ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $meldung ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! $fertig ) : ?>
			<div class="notice notice-warning"><p>Noch nicht eingerichtet: Client-ID und Geheimnis der Microsoft-App eintragen.</p></div>
		<?php endif; ?>

		<h2>Adressen</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Adresse für Claude<br><small>Konnektor-URL</small></th>
				<td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( vljcr_mcp_url() ); ?>">
				<p class="description">Nicht geheim: Ohne Anmeldung mit einem freigeschalteten Microsoft-Konto kommt niemand hinein.</p></td>
			</tr>
			<tr>
				<th scope="row">Umleitungs-URI für Microsoft Entra<br><small>Plattform „Web“</small></th>
				<td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( vljcr_ms_redirect_uri() ); ?>"></td>
			</tr>
		</table>

		<form method="post">
			<?php wp_nonce_field( 'vljcr_einstellungen' ); ?>
			<input type="hidden" name="vljcr_aktion" value="speichern">
			<h2>Anmeldung über Microsoft</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vljcr_ms_mandant">Mandanten-ID (Tenant)</label></th>
					<td><input type="text" class="regular-text code" id="vljcr_ms_mandant" name="vljcr_ms_mandant" value="<?php echo esc_attr( get_option( 'vljcr_ms_mandant' ) ); ?>">
					<p class="description">Voreingestellt: Junge Liberale Niedersachsen e.V.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vljcr_ms_client_id">Anwendungs-ID (Client-ID)</label></th>
					<td><input type="text" class="regular-text code" id="vljcr_ms_client_id" name="vljcr_ms_client_id" value="<?php echo esc_attr( get_option( 'vljcr_ms_client_id' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vljcr_ms_secret">Geheimer Clientschlüssel (Wert)</label></th>
					<td><input type="password" class="regular-text code" id="vljcr_ms_secret" name="vljcr_ms_secret" value="" autocomplete="new-password" placeholder="<?php echo get_option( 'vljcr_ms_secret' ) ? 'gespeichert – leer lassen, um ihn zu behalten' : ''; ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vljcr_konten">Erlaubte Konten</label></th>
					<td><textarea class="large-text code" rows="3" id="vljcr_konten" name="vljcr_konten"><?php echo esc_textarea( get_option( 'vljcr_konten' ) ); ?></textarea>
					<p class="description">Je Zeile: Adresse und Objekt-ID. Mit Objekt-ID zählt nur diese (sie ändert sich nie). Jedes andere Konto wird abgelehnt.</p></td>
				</tr>
			</table>
			<h2>Betrieb</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Konnektor eingeschaltet</th>
					<td><label><input type="checkbox" name="vljcr_aktiv" value="1" <?php checked( $aktiv, 1 ); ?>> Claude darf nach Anmeldung zugreifen</label></td>
				</tr>
				<tr>
					<th scope="row">Claude arbeitet als</th>
					<td>
						<select name="vljcr_user">
							<?php foreach ( $users as $u ) : ?>
								<option value="<?php echo (int) $u->ID; ?>" <?php selected( $uid, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Änderungen erscheinen in den Revisionen unter diesem WordPress-Benutzer. Für Menüs braucht er Administratorrechte.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Aktive Anmeldungen</th>
					<td><?php echo $angem ? esc_html( implode( ', ', $angem ) ) : 'keine'; ?></td>
				</tr>
				<tr>
					<th scope="row">Server-Prüfung</th>
					<td>
						<?php
						if ( true === $kopf ) {
							echo 'Der Server reicht die Anmeldung an WordPress weiter.';
						} elseif ( false === $kopf ) {
							echo '<strong style="color:#b32d2e">Der Server entfernt den Authorization-Kopf. Claude wird abgewiesen.</strong> Lösung bei Apache: in die .htaccess vor „# BEGIN WordPress“ die Zeile <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code>, sonst den Hoster fragen.';
						} elseif ( null === $kopf ) {
							echo 'Prüfung nicht möglich (die Website kann sich selbst nicht aufrufen).';
						} else {
							echo '<a href="' . esc_url( admin_url( 'tools.php?page=vlj-claude-redaktion&diagnose=1' ) ) . '">Jetzt prüfen</a>';
						}
						?>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Speichern' ); ?>
		</form>
		<form method="post" style="margin-bottom:8px">
			<?php wp_nonce_field( 'vljcr_einstellungen' ); ?>
			<input type="hidden" name="vljcr_aktion" value="updates">
			<?php submit_button( 'Jetzt nach Updates suchen (Version ' . VLJCR_VERSION . ')', 'secondary', 'submit', false ); ?>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'vljcr_einstellungen' ); ?>
			<input type="hidden" name="vljcr_aktion" value="widerrufen">
			<?php submit_button( 'Alle Anmeldungen widerrufen', 'secondary', 'submit', false, array( 'onclick' => "return confirm('Claude verliert sofort den Zugang und muss sich neu anmelden. Fortfahren?');" ) ); ?>
		</form>

		<h2>Protokoll (letzte 50 Einträge)</h2>
		<?php if ( ! $log ) : ?>
			<p>Noch keine Einträge.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Zeit</th><th>Aktion</th><th>Inhalt</th><th>Freigabe</th><th>Ergebnis</th></tr></thead>
				<tbody>
				<?php foreach ( $log as $e ) : ?>
					<tr<?php echo ! empty( $e['fehler'] ) ? ' style="color:#b32d2e"' : ''; ?>>
						<td><?php echo esc_html( $e['zeit'] ); ?></td>
						<td><?php echo esc_html( $e['werkzeug'] ); ?></td>
						<td><?php echo ! empty( $e['id'] ) ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $e['id'] . '&action=edit' ) ) . '">' . (int) $e['id'] . '</a>' : ''; ?></td>
						<td><?php echo esc_html( $e['freigabe'] ); ?></td>
						<td><?php echo esc_html( $e['ergebnis'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
