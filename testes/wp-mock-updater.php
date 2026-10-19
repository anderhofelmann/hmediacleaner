<?php
/**
 * Simulador minimo de WordPress para testar o atualizador do HMediaCleaner
 * sem precisar de uma instalacao real.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-wp/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['wp_filters']  = array();
$GLOBALS['wp_options']  = array();
$GLOBALS['transients']  = array();
$GLOBALS['http_calls']  = array();

function add_action( $hook, $cb, $prio = 10, $args = 1 ) {}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {}
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return $t; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function __( $t, $d = '' ) { return $t; }
function _e( $t, $d = '' ) { echo $t; }
function _x( $t, $c = '', $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 === $n ? $s : $p; }
function wp_kses_post( $t ) { return $t; }
function home_url( $p = '' ) { return 'https://exemplo.test'; }
function admin_url( $p = '' ) { return 'https://exemplo.test/wp-admin/' . ltrim( $p, '/' ); }
function add_query_arg( $a, $u ) {
	return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $a );
}
function get_option( $k, $d = false ) { return $GLOBALS['wp_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['wp_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['wp_options'][ $k ] ); return true; }
function get_transient( $k ) {
	$t = $GLOBALS['transients'][ $k ] ?? false;
	return $t['value'] ?? false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['transients'][ $k ] = array( 'value' => $v, 'ttl' => $ttl );
	return true;
}
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function is_admin() { return true; }
function current_user_can( $c ) { return true; }
function get_current_screen() { return $GLOBALS['current_screen'] ?? null; }
class WP_Screen { public $id = 'plugins-page'; }
class WP_Error {
	public $code;
	public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/** Resposta simulada da API do GitHub. */
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['http_calls'][] = $url;
	$mock = $GLOBALS['http_response'] ?? null;

	if ( ! $mock ) {
		return new WP_Error( 'http_request_failed', 'sem rede no teste' );
	}
	if ( isset( $mock['error'] ) ) {
		return new WP_Error( 'http_request_failed', $mock['error'] );
	}

	return array(
		'response' => array( 'code' => $mock['code'] ?? 200 ),
		'body'     => $mock['body'] ?? '',
	);
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
}

/** Download simulado, para conferir o hash. */
function download_url( $u, $t = 60 ) {
	$destino   = sys_get_temp_dir() . '/hmc-test-' . md5( $u ) . '.zip';
	$conteudo  = $GLOBALS['download_content'] ?? null;

	if ( null === $conteudo ) {
		return new WP_Error( 'download_failed', 'sem conteudo no teste' );
	}

	file_put_contents( $destino, $conteudo );
	return $destino;
}

/** Transiente vazio de atualização. */
function transiente_vazio() {
	$t = new stdClass();
	$t->response     = array();
	$t->no_update    = array();
	$t->checked      = array();
	$t->last_checked = time();
	return $t;
}