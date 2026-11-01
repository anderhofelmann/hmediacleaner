<?php
/**
 * Testes de integração do HMediaCleaner. Rodar SOMENTE num WordPress DESCARTÁVEL: o teste cria
 * anexos e arquivos de verdade e tenta, de propósito, ataques de caminho contra a quarentena.
 *
 *   wp eval-file testes/integracao-wordpress.php
 *
 * Cobre: duplicatas em duas fases (início do arquivo + hash completo), arquivos órfãos e a segurança
 * da quarentena contra caminhos adulterados nos metadados (../), links simbólicos e contagem de acessos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
// O wp eval-file executa dentro de uma função: o contador precisa ser global de verdade.
$GLOBALS['hmc_falhas'] = 0;
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

$tag  = 'it' . substr( md5( microtime() ), 0, 6 );
$up   = wp_upload_dir( null, false );
$base = trailingslashit( $up['basedir'] );
$mes  = gmdate( 'Y/m' ) . '/';
wp_mkdir_p( $base . $mes );

/** Anexo "cru": o arquivo existe e o anexo o registra, sem metadados de miniaturas. */
function hmc_t_raw( $name, $bytes ) {
	global $wpdb;
	$up = wp_upload_bits( $name, null, $bytes );
	if ( ! empty( $up['error'] ) ) {
		return 0;
	}
	$id  = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ), $up['file'] );
	$old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ); // Fora da proteção de envios recentes.
	$wpdb->update( $wpdb->posts, array( 'post_date' => $old, 'post_date_gmt' => $old ), array( 'ID' => $id ) );
	return (int) $id;
}

function hmc_t_scan() {
	$sc = new HMC_Scanner();
	$sc->start();
	do {
		$st = $sc->run( 30 );
	} while ( empty( $st['finished'] ) );
}

/* ====================================================================== preparação */

$bloco = random_bytes( 200000 );
$A     = hmc_t_raw( "$tag-a.png", $bloco );
$B     = hmc_t_raw( "$tag-b.png", $bloco ); // Cópia idêntica de A.
$cauda = $bloco;
$cauda[199999] = chr( ord( $cauda[199999] ) ^ 1 );
$C     = hmc_t_raw( "$tag-c.png", $cauda ); // Mesmo tamanho e mesmo início de A, final diferente.
$cabeca = $bloco;
$cabeca[10] = chr( ord( $cabeca[10] ) ^ 1 );
$D     = hmc_t_raw( "$tag-d.png", $cabeca ); // Mesmo tamanho, início diferente.
$X     = hmc_t_raw( "$tag-x.png", random_bytes( 3000 ) );

// Órfãos de verdade, no disco.
$arq = array(
	"{$mes}$tag-x-150x150.png"         => 'miniatura',
	"{$mes}$tag-x-scaled.png"          => 'scaled',
	"{$mes}$tag-x-e1700000000123.png"  => 'editada',
	"{$mes}$tag-x.png.webp"            => 'webp',
	"{$mes}$tag-solto.jpg"             => 'solto',
	"{$mes}$tag-solto-usado.jpg"       => 'solto usado',
	"{$mes}$tag-erro.log"              => 'log',
	"{$mes}$tag-zero.png"              => '',
	"{$mes}sub/$tag-aninhado.png"      => 'aninhado',
	"$tag-raiz.png"                    => 'na raiz',
	"$tag-plugin/ignorado.png"         => 'pasta de plugin: fora do escopo',
);
foreach ( $arq as $rel => $conteudo ) {
	wp_mkdir_p( dirname( $base . $rel ) );
	file_put_contents( $base . $rel, $conteudo );
}
wp_insert_post( array( 'post_type' => 'page', 'post_title' => "Usa $tag", 'post_status' => 'publish', 'post_content' => '<img src="' . trailingslashit( $up['baseurl'] ) . "{$mes}$tag-solto-usado.jpg" . '">' ) );
file_put_contents( WP_CONTENT_DIR . "/$tag-vitima-link.txt", 'alvo do link' );
@symlink( WP_CONTENT_DIR . "/$tag-vitima-link.txt", $base . "{$mes}$tag-link.png" );

hmc_t_scan();
$t = HMC_DB::items_table();
// A versão 0.3.0 não tem a procura de órfãos: nela, só as demais seções rodam (serve para comparar).
$tem_orfaos = class_exists( 'HMC_Orphans' );
$o          = $tem_orfaos ? HMC_DB::orphans_table() : '';
$g = function ( $id ) use ( $wpdb, $t ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT status, dup_of FROM {$t} WHERE attachment_id = %d", $id ) );
};

/* ====================================================================== duplicatas */

echo "Duplicatas (duas fases)\n";
hmc_ok( 'cópia idêntica é marcada como duplicata do original', (string) $A, (string) $g( $B )->dup_of );
hmc_ok( 'o original não é duplicata de ninguém', null, $g( $A )->dup_of );
hmc_ok( 'mesmo tamanho e mesmo início, final diferente: NÃO é duplicata', null, $g( $C )->dup_of );
hmc_ok( 'mesmo tamanho, início diferente: NÃO é duplicata', null, $g( $D )->dup_of );
hmc_ok( 'nenhum hash provisório ficou para trás', 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE hash = 'pending' OR hash LIKE 'q:%'" ) );

/* ====================================================================== órfãos */

if ( $tem_orfaos ) {
echo "Arquivos órfãos\n";
$kind = function ( $rel ) use ( $wpdb, $o ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT kind FROM {$o} WHERE path_key = %s", HMC_Orphans::key( $rel ) ) );
};
hmc_ok( 'miniatura de tamanho não registrado', 'thumb', $kind( "{$mes}$tag-x-150x150.png" ) );
hmc_ok( 'versão -scaled sem registro', 'scaled', $kind( "{$mes}$tag-x-scaled.png" ) );
hmc_ok( 'versão editada', 'edit', $kind( "{$mes}$tag-x-e1700000000123.png" ) );
hmc_ok( 'cópia WebP de imagem registrada', 'webp', $kind( "{$mes}$tag-x.png.webp" ) );
hmc_ok( 'arquivo solto', 'loose', $kind( "{$mes}$tag-solto.jpg" ) );
hmc_ok( 'solto, mas referenciado pelo site, não é candidato', 'in_use', $kind( "{$mes}$tag-solto-usado.jpg" ) );
hmc_ok( 'log', 'junk', $kind( "{$mes}$tag-erro.log" ) );
hmc_ok( 'arquivo vazio', 'empty', $kind( "{$mes}$tag-zero.png" ) );
hmc_ok( 'arquivo em subpasta da pasta de data', 'loose', $kind( "{$mes}sub/$tag-aninhado.png" ) );
hmc_ok( 'arquivo solto na raiz de uploads', 'loose', $kind( "$tag-raiz.png" ) );
hmc_ok( 'pasta de plugin fica fora do escopo', null, $kind( "$tag-plugin/ignorado.png" ) );
hmc_ok( 'link simbólico nunca é seguido', null, $kind( "{$mes}$tag-link.png" ) );
hmc_ok( 'arquivo registrado por anexo não é órfão', null, $kind( "{$mes}$tag-x.png" ) );
hmc_ok( 'index.php não entra', null, $kind( "{$mes}index.php" ) );
$sum = get_option( 'hmc_orphan_summary' );
hmc_ok( 'resumo de órfãos gravado', true, is_array( $sum ) && isset( $sum['kinds']['thumb'] ) );
}

/* ====================================================================== segurança */

echo "Segurança: caminhos adulterados nos metadados\n";

// 1) _wp_attached_file apontando para fora de uploads.
$v1 = WP_CONTENT_DIR . "/$tag-vitima1.txt";
file_put_contents( $v1, 'vítima 1' );
$E = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => "$tag-e", 'post_mime_type' => 'image/png' ) );
update_post_meta( $E, '_wp_attached_file', "../$tag-vitima1.txt" );
hmc_ok( 'attachment_files recusa "../" em _wp_attached_file', array(), HMC_Quarantine::attachment_files( $E ) );
HMC_Quarantine::quarantine( $E );
hmc_ok( 'a quarentena não move o arquivo de fora de uploads (1)', true, file_exists( $v1 ) );
hmc_ok( 'nada foi parar na pasta da quarentena (1)', false, file_exists( HMC_Quarantine::dir() . "/$E" ) );

// 2) Tamanho (sizes[].file) apontando para fora de uploads.
$v2 = WP_CONTENT_DIR . "/$tag-vitima2.txt";
file_put_contents( $v2, 'vítima 2' );
$F = hmc_t_raw( "$tag-f.png", random_bytes( 2000 ) );
update_post_meta( $F, '_wp_attachment_metadata', array( 'sizes' => array( 'x' => array( 'file' => "../../../$tag-vitima2.txt" ) ) ) );
$achados = HMC_Quarantine::attachment_files( $F );
hmc_ok( 'attachment_files ignora nome de tamanho com "../"', true, ! in_array( "{$mes}../../../$tag-vitima2.txt", $achados, true ) && 1 === count( $achados ) );
$r = HMC_Quarantine::quarantine( $F );
hmc_ok( 'a quarentena não move o arquivo de fora de uploads (2)', true, file_exists( $v2 ) );
HMC_Quarantine::restore( $F );

// 3) Dados da quarentena adulterados: a restauração não pode tirar um arquivo de fora nem escrevê-lo fora de uploads.
$v3 = WP_CONTENT_DIR . "/$tag-vitima3.txt";
file_put_contents( $v3, 'vítima 3' );
$H = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'hmc_quarantine', 'post_title' => "$tag-h", 'post_mime_type' => 'image/png' ) );
update_post_meta( $H, '_hmc_quarantine', array( 'date' => time(), 'files' => array( "../../../$tag-vitima3.txt" ), 'status' => 'inherit', 'user' => 0, 'hits' => 0, 'last_hit' => 0, 'referer' => '' ) );
$res = HMC_Quarantine::restore( $H );
hmc_ok( 'restauração com caminho adulterado devolve erro', true, is_string( $res ) );
hmc_ok( 'o arquivo de fora continua no lugar', true, file_exists( $v3 ) );
hmc_ok( 'nada foi escrito acima de wp-content', false, file_exists( dirname( WP_CONTENT_DIR ) . "/$tag-vitima3.txt" ) );

// 4) Link simbólico dentro de uploads não é movido.
$G = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => "$tag-g", 'post_mime_type' => 'image/png' ) );
update_post_meta( $G, '_wp_attached_file', "{$mes}$tag-link.png" );
hmc_ok( 'link simbólico não entra na lista de arquivos do anexo', array(), HMC_Quarantine::attachment_files( $G ) );

/* ====================================================================== quarentena, contagem de acessos */

echo "Quarentena normal e contagem de acessos\n";
hmc_t_scan();
$rel = get_post_meta( $B, '_wp_attached_file', true );
$q   = HMC_Quarantine::quarantine( $B );
hmc_ok( 'quarentena de um arquivo legítimo funciona', true, $q );
hmc_ok( 'o arquivo saiu de uploads', false, file_exists( $base . $rel ) );
hmc_ok( 'e está na pasta da quarentena', true, file_exists( HMC_Quarantine::dir() . "/$B/$rel" ) );
hmc_ok( 'a pasta da quarentena é protegida (web.config)', true, file_exists( HMC_Quarantine::dir() . '/web.config' ) );

$GLOBALS['wp_query']->is_404 = true;
$_SERVER['REQUEST_URI']      = '/wp-content/uploads/' . $rel;
HMC_Quarantine::track_404();
HMC_Quarantine::track_404();
HMC_Quarantine::track_404();
hmc_ok( 'três pedidos seguidos contam como um só (limite de frequência)', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT q_hits FROM {$t} WHERE attachment_id = %d", $B ) ) );
delete_transient( 'hmc_hit_' . $B );
HMC_Quarantine::track_404();
hmc_ok( 'passado o intervalo, volta a contar', 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT q_hits FROM {$t} WHERE attachment_id = %d", $B ) ) );

$rs = HMC_Quarantine::restore( $B );
hmc_ok( 'restauração devolve o arquivo', true, $rs );
hmc_ok( 'o arquivo voltou ao lugar', true, file_exists( $base . $rel ) );

/* ====================================================================== limpeza */

foreach ( array( $A, $B, $C, $D, $X, $E, $F, $G, $H ) as $id ) {
	wp_delete_attachment( $id, true );
}
foreach ( array_keys( $arq ) as $rel2 ) {
	@unlink( $base . $rel2 );
}
@rmdir( $base . "{$mes}sub" );
@rmdir( $base . "$tag-plugin" );
@unlink( $base . "{$mes}$tag-link.png" );
foreach ( array( $v1, $v2, $v3, WP_CONTENT_DIR . "/$tag-vitima-link.txt" ) as $f ) {
	@unlink( $f );
}

echo "\n{$GLOBALS['hmc_total']} verificações, {$GLOBALS['hmc_falhas']} falha(s).\n";
exit( $GLOBALS['hmc_falhas'] ? 1 : 0 );
