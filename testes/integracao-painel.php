<?php
/**
 * Renderiza cada aba do painel num WordPress DESCARTÁVEL e confere conteúdo e ausência de avisos de PHP.
 *
 *   wp eval-file testes/integracao-painel.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$GLOBALS["hmc_falhas"] = 0;
$GLOBALS['hmc_total']  = 0;

function hmc_ok( $nome, $esperado, $obtido ) {
	$GLOBALS['hmc_total']++;
	if ( $esperado === $obtido ) {
		echo "  ok   $nome\n";
		return;
	}
	$GLOBALS['hmc_falhas']++;
	echo "  FALHA $nome\n        esperado: " . var_export( $esperado, true ) . "\n        obtido:   " . var_export( $obtido, true ) . "\n";
}

wp_set_current_user( 1 );
$_SERVER['HTTP_HOST'] = 'localhost';
set_current_screen( 'media_page_hmediacleaner' );
require_once HMC_DIR . 'includes/class-hmc-admin.php';

function hmc_render( $get ) {
	$_GET = array_merge( array( 'page' => 'hmediacleaner' ), $get );
	$a    = new HMC_Admin();
	ob_start();
	$a->page();
	return ob_get_clean();
}

// Garante uma análise recente e algo em quarentena para as telas terem conteúdo.
$sc = new HMC_Scanner();
$sc->start();
do {
	$st = $sc->run( 30 );
} while ( empty( $st['finished'] ) );

$tem_aviso = function ( $html ) {
	return (bool) preg_match( '/(Warning|Notice|Fatal error|Deprecated|Parse error)\s*:/', wp_strip_all_tags( $html ) );
};

echo "Visão geral (padrão, sem parâmetros)\n";
$h = hmc_render( array() );
hmc_ok( 'mostra o título da aba ativa', true, false !== strpos( $h, 'nav-tab nav-tab-active">Visão geral' ) );
hmc_ok( 'mostra o espaço que dá para recuperar', true, false !== strpos( $h, 'Espaço que você pode recuperar' ) );
hmc_ok( 'mostra o plano recomendado', true, false !== strpos( $h, 'Plano recomendado' ) );
hmc_ok( 'tem botão de pré-visualização', true, false !== strpos( $h, 'data-op="preview"' ) );
hmc_ok( 'tem botão de quarentena', true, false !== strpos( $h, 'data-op="quarantine"' ) );
hmc_ok( 'o botão de pré-visualização não pede confirmação', true, (bool) preg_match( '/data-op="preview"[^>]*data-confirm=""/', $h ) );
hmc_ok( 'mostra o gráfico de espaço', true, false !== strpos( $h, 'hmc-chart' ) );
hmc_ok( 'sem avisos de PHP', false, $tem_aviso( $h ) );

echo "Relatório e outras abas\n";
$h = hmc_render( array( 'tab' => 'report' ) );
hmc_ok( 'Relatório: cartões de resumo', true, false !== strpos( $h, 'hmc-cards' ) );
hmc_ok( 'Relatório: sem avisos de PHP', false, $tem_aviso( $h ) );
$h = hmc_render( array( 'view' => 'unused' ) );
hmc_ok( 'filtro do relatório sem "tab" cai no Relatório', true, false !== strpos( $h, 'nav-tab nav-tab-active">Relatório' ) );
$h = hmc_render( array( 'tab' => 'orphans' ) );
hmc_ok( 'Órfãos: coluna de risco', true, false !== strpos( $h, 'hmc-risk' ) );
hmc_ok( 'Órfãos: sem avisos de PHP', false, $tem_aviso( $h ) );
$h = hmc_render( array( 'tab' => 'history' ) );
hmc_ok( 'Histórico renderiza', true, false !== strpos( $h, 'Tudo o que o plugin moveu' ) );
hmc_ok( 'Histórico: sem avisos de PHP', false, $tem_aviso( $h ) );
$h = hmc_render( array( 'tab' => 'settings' ) );
hmc_ok( 'Configurações renderiza', true, false !== strpos( $h, 'retention_days' ) );
$h = hmc_render( array( 'tab' => 'about' ) );
hmc_ok( 'Sobre renderiza e cita os órfãos', true, false !== strpos( $h, 'Arquivos órfãos' ) );
$h = hmc_render( array( 'tab' => '"><script>alert(1)</script>' ) );
hmc_ok( 'aba inválida cai na Visão geral (sem refletir o texto)', true, false !== strpos( $h, 'nav-tab nav-tab-active">Visão geral' ) && false === strpos( $h, '<script>alert(1)' ) );

echo "Escape de conteúdo vindo de arquivos\n";
$up   = wp_upload_dir( null, false );
$nome = '2020/01/x<img src=x onerror=alert(1)>.log';
$wpdb->replace( HMC_DB::oq_table(), array( 'path_key' => md5( 'xss' ), 'fkey' => '', 'path' => $nome, 'kind' => 'junk', 'bytes' => 1, 'q_status' => 'quarantined', 'q_date' => current_time( 'mysql' ), 'q_hits' => 2 ) );
$h = hmc_render( array( 'tab' => 'orphans' ) );
hmc_ok( 'nome de arquivo com HTML aparece escapado no painel', true, false !== strpos( $h, '&lt;img src=x onerror=alert(1)&gt;' ) && false === strpos( $h, '<img src=x onerror' ) );
$wpdb->delete( HMC_DB::oq_table(), array( 'path_key' => md5( 'xss' ) ) );

echo "\n{$GLOBALS['hmc_total']} verificações, {$GLOBALS['hmc_falhas']} falha(s).\n";
exit( $GLOBALS['hmc_falhas'] ? 1 : 0 );
