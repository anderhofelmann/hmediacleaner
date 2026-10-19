<?php
/**
 * Testes do atualizador do HMediaCleaner.
 *
 * A API do GitHub é simulada em $GLOBALS['http_response'].
 * Rodar: php testes/teste-atualizador.php
 */

require_once __DIR__ . '/wp-mock-updater.php';

define( 'HMC_DIR', __DIR__ . '/../hmediacleaner/' );
define( 'HMC_VERSION', '0.3.0' );

require_once HMC_DIR . 'includes/class-hmc-updater.php';

$fails = 0;
$total = 0;

function check( $desc, $esperado, $obtido ) {
	global $fails, $total;
	$total++;
	$ok = ( $esperado === $obtido );
	if ( ! $ok ) {
		$fails++;
	}
	printf(
		"  %s %-56s esp: %-22s obt: %s\n",
		$ok ? 'OK  ' : 'FALHA',
		$desc,
		var_export( $esperado, true ),
		var_export( $obtido, true )
	);
}

/** Monta a resposta simulada da API do GitHub. */
function github( $tag, $com_zip = true, $corpo_extra = '', $code = 200 ) {
	$assets = array();

	if ( $com_zip ) {
		$assets[] = array(
			'name'                 => 'hmediacleaner.zip',
			'browser_download_url' => 'https://github.com/anderhofelmann/hmediacleaner/releases/download/' . $tag . '/hmediacleaner.zip',
		);
	}

	$GLOBALS['http_response'] = array(
		'code' => $code,
		'body' => json_encode(
			array(
				'tag_name' => $tag,
				'html_url' => 'https://github.com/anderhofelmann/hmediacleaner/releases/tag/' . $tag,
				'body'     => $corpo_extra,
				'assets'   => $assets,
			)
		),
	);
}

/** Zera caches entre testes. */
function limpar() {
	$GLOBALS['transients'] = array();
	$GLOBALS['http_calls'] = array();
}

echo "\n[1] Normalização de versão\n";

check( 'remove o "v"',       '0.4.0', HMC_Updater::normalize_version( 'v0.4.0' ) );
check( 'remove "V"',        '0.4.0', HMC_Updater::normalize_version( 'V0.4.0' ) );
check( 'remove barra final','0.4.0', HMC_Updater::normalize_version( '0.4.0/' ) );
check( 'versão instalada',  HMC_VERSION, HMC_Updater::current_version() );

echo "\n[2] Versão nova\n";

limpar();
github( 'v0.2.0' ); // anterior
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'versão anterior é ignorada', 0, count( $t->response ) );

limpar();
github( 'v0.3.0' ); // igual
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'mesma versão é ignorada', 0, count( $t->response ) );

limpar();
github( 'v0.4.0' ); // nova
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'versão nova é detectada', 1, count( $t->response ) );
check( 'consulta a API do GitHub', 1, count( $GLOBALS['http_calls'] ) );
check( 'URL correta da API', 'https://api.github.com/repos/anderhofelmann/hmediacleaner/releases/latest', $GLOBALS['http_calls'][0] );

$u = $t->response['hmediacleaner/hmediacleaner.php'];
check( 'nova versão preenchida', '0.4.0', $u->new_version );
check( 'slug do plugin', 'hmediacleaner', $u->slug );
check( 'caminho do plugin', 'hmediacleaner/hmediacleaner.php', $u->plugin );
check(
	'ZIP aponta para a release',
	'https://github.com/anderhofelmann/hmediacleaner/releases/download/v0.4.0/hmediacleaner.zip',
	$u->package
);
check( 'aviso de atualização', 1,
	false !== strpos( $u->upgrade_notice, '0.4.0' ) && false !== strpos( $u->upgrade_notice, HMC_VERSION ) ? 1 : 0 );

echo "\n[3] Cache\n";

limpar();
github( 'v0.4.0' );
HMC_Updater::get_release_info();
HMC_Updater::get_release_info();
HMC_Updater::get_release_info();
check( 'três leituras, uma requisição', 1, count( $GLOBALS['http_calls'] ) );

delete_transient( HMC_Updater::CACHE_KEY );
delete_transient( HMC_Updater::CACHE_BRANCH );
HMC_Updater::get_release_info();
check( 'cache expirado volta a consultar', 2, count( $GLOBALS['http_calls'] ) );

echo "\n[4] Falhas não quebram o painel\n";

$GLOBALS['http_response'] = null;
limpar();
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'sem rede: nada acontece', 0, count( $t->response ) );

limpar();
github( 'v0.4.0', true, '', 403 );
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'HTTP 403: nada acontece', 0, count( $t->response ) );

limpar();
github( 'v0.4.0', false );
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'release sem ZIP é ignorada', 0, count( $t->response ) );

$GLOBALS['http_response'] = array( 'code' => 200, 'body' => 'nao e json' );
limpar();
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'resposta inválida é ignorada', 0, count( $t->response ) );

echo "\n[5] Integridade do pacote\n";

$hash = str_repeat( 'a', 64 );
check( 'lê sha256: da release',   $hash, HMC_Updater::extract_sha256( "sha256: $hash" ) );
check( 'lê SHA256 maiúsculo',     $hash, HMC_Updater::extract_sha256( "SHA256: $hash" ) );
check( 'lê com crase',            $hash, HMC_Updater::extract_sha256( "sha256: `$hash`" ) );
check( 'ignora hash inválido',    '',    HMC_Updater::extract_sha256( 'sha256: abc' ) );
check( 'sem hash devolve vazio',  '',    HMC_Updater::extract_sha256( 'texto' ) );

// Release sem hash: a atualização segue.
limpar();
github( 'v0.4.0' );
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'sem hash: atualização liberada', 1, count( $t->response ) );

// Pacote em http é recusado.
$GLOBALS['transients'] = array();
$GLOBALS['http_calls'] = array();
$GLOBALS['http_response'] = array(
	'code' => 200,
	'body' => json_encode(
		array(
			'tag_name' => 'v0.4.0',
			'assets'   => array(
				array(
					'name'                 => 'hmediacleaner.zip',
					'browser_download_url' => 'http://exemplo.test/hmediacleaner.zip',
				),
			),
		)
	),
);
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'pacote em http é recusado', 0, count( $t->response ) );

// Hash confere: passa.
$pacote   = 'conteudo-do-plugin';
$hash_real = hash( 'sha256', $pacote );

$GLOBALS['transients']        = array();
$GLOBALS['http_calls']        = array();
$GLOBALS['download_content']  = $pacote;
$GLOBALS['http_response']     = array(
	'code' => 200,
	'body' => json_encode(
		array(
			'tag_name' => 'v0.4.0',
			'body'     => "sha256: $hash_real",
			'assets'   => array(
				array(
					'name'                 => 'hmediacleaner.zip',
					'browser_download_url' => 'https://github.com/x/hmediacleaner.zip',
				),
			),
		)
	),
);
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'hash confere: atualização liberada', 1, count( $t->response ) );

// Hash divergente: bloqueia.
$GLOBALS['transients']    = array();
$GLOBALS['http_calls']    = array();
$GLOBALS['http_response'] = array(
	'code' => 200,
	'body' => json_encode(
		array(
			'tag_name' => 'v0.4.0',
			'body'     => 'sha256: ' . str_repeat( 'b', 64 ),
			'assets'   => array(
				array(
					'name'                 => 'hmediacleaner.zip',
					'browser_download_url' => 'https://github.com/x/hmediacleaner.zip',
				),
			),
		)
	),
);
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'hash divergente: atualização bloqueada', 0, count( $t->response ) );

// Falha no download também bloqueia.
$GLOBALS['transients']       = array();
$GLOBALS['http_calls']       = array();
$GLOBALS['download_content'] = null;
$GLOBALS['http_response']    = array(
	'code' => 200,
	'body' => json_encode(
		array(
			'tag_name' => 'v0.4.0',
			'body'     => "sha256: $hash_real",
			'assets'   => array(
				array(
					'name'                 => 'hmediacleaner.zip',
					'browser_download_url' => 'https://github.com/x/hmediacleaner.zip',
				),
			),
		)
	),
);
$t = HMC_Updater::check_update( transiente_vazio() );
check( 'falha no download: bloqueada', 0, count( $t->response ) );

$GLOBALS['download_content'] = $pacote;

echo "\n[6] Tela de detalhes\n";

limpar();
github( 'v0.4.0' );
$args       = new stdClass();
$args->slug = 'hmediacleaner';
$info = HMC_Updater::plugin_info( false, 'plugin_information', $args );
check( 'devolve os detalhes', true, is_object( $info ) );
check( 'nome do plugin', 'HMediaCleaner', $info->name );
check( 'versão mais recente', '0.4.0', $info->version );
check( 'link do download', 1, false !== strpos( $info->download_link, 'hmediacleaner.zip' ) ? 1 : 0 );

$outro       = new stdClass();
$outro->slug = 'woocommerce';
check( 'outro slug não é tocado', false, HMC_Updater::plugin_info( false, 'plugin_information', $outro ) );
check( 'outra ação não é tocada', false, HMC_Updater::plugin_info( false, 'query_plugins', $args ) );

echo "\n[7] Registro no plugin\n";

$bootstrap = file_get_contents( HMC_DIR . 'hmediacleaner.php' );
check( 'classe do atualizador é carregada', 1,
	false !== strpos( $bootstrap, 'class-hmc-updater.php' ) ? 1 : 0 );
check( 'init é chamado', 1, false !== strpos( $bootstrap, 'HMC_Updater::init()' ) ? 1 : 0 );
check( 'arquivo existe', true, file_exists( HMC_DIR . 'includes/class-hmc-updater.php' ) );

$uninstall = file_get_contents( HMC_DIR . 'uninstall.php' );
check( 'desinstalação apaga o cache', 1,
	false !== strpos( $uninstall, "delete_transient( 'hmc_update_check' )" ) ? 1 : 0 );

echo "\n" . str_repeat( '-', 74 ) . "\n";

if ( 0 === $fails ) {
	echo "RESULTADO: todos os $total testes passaram\n";
} else {
	echo "RESULTADO: $fails de $total testes FALHARAM\n";
}

exit( 0 === $fails ? 0 : 1 );