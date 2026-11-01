<?php
/**
 * Testes da classificação de arquivos órfãos (não precisam de WordPress).
 *
 *   php testes/teste-orfaos.php
 */

define( 'ABSPATH', __DIR__ . '/' );
function __( $t, $d = '' ) { return $t; }
require __DIR__ . '/../hmediacleaner/includes/class-hmc-orphans.php';

$falhas = 0;
$total  = 0;

function confere( $nome, $esperado, $obtido ) {
	global $falhas, $total;
	$total++;
	if ( $esperado === $obtido ) {
		echo "  ok   $nome\n";
		return;
	}
	$falhas++;
	echo "  FALHA $nome\n        esperado: " . var_export( $esperado, true ) . "\n        obtido:   " . var_export( $obtido, true ) . "\n";
}

// Caminhos que algum anexo registra (em minúsculas, como o plugin compara).
$registrados = array( '2026/10/foto.jpg', '2026/10/foto-300x200.jpg', '2026/10/logo.png', 'logo.png', '2026/09/capa.jpeg' );
$conhecido   = function ( $rel ) use ( $registrados ) {
	return in_array( strtolower( $rel ), $registrados, true );
};
$referenciados = array( '2026/10/solto-em-uso.jpg' );
$em_uso        = function ( $rel ) use ( $referenciados ) {
	return in_array( $rel, $referenciados, true );
};
$tipo = function ( $rel, $bytes = 1000 ) use ( $conhecido, $em_uso ) {
	return HMC_Orphans::kind_for( $rel, $bytes, $conhecido, $em_uso );
};

echo "Derivados de um arquivo registrado\n";
confere( 'miniatura de tamanho não registrado', 'thumb', $tipo( '2026/10/foto-150x150.jpg' ) );
confere( 'versão -scaled sem registro', 'scaled', $tipo( '2026/10/foto-scaled.jpg' ) );
confere( 'versão editada', 'edit', $tipo( '2026/10/foto-e1700000000123.jpg' ) );
confere( 'versão rotacionada', 'edit', $tipo( '2026/10/foto-rotated.jpg' ) );
confere( 'miniatura de uma versão -scaled', 'thumb', $tipo( '2026/10/foto-scaled-150x150.jpg' ) );
confere( 'cópia de segurança de compressão', 'junk', $tipo( '2026/10/foto-updraft-pre-smush-original.jpg' ) );
confere( 'WebP por acréscimo (foto.jpg.webp)', 'webp', $tipo( '2026/10/foto.jpg.webp' ) );
confere( 'WebP por troca de extensão (logo.webp)', 'webp', $tipo( '2026/10/logo.webp' ) );
confere( 'maiúsculas não atrapalham', 'thumb', $tipo( '2026/10/FOTO-150x150.JPG' ) );
confere( 'arquivo na raiz de uploads', 'thumb', $tipo( 'logo-300x300.png' ) );

echo "Sem arquivo base\n";
confere( 'miniatura cujo original não existe é solta', 'loose', $tipo( '2026/10/outra-150x150.jpg' ) );
confere( 'arquivo solto', 'loose', $tipo( '2026/10/solto.jpg' ) );
confere( 'solto, mas referenciado pelo site', 'in_use', $tipo( '2026/10/solto-em-uso.jpg' ) );
confere( 'WebP sem original', 'loose', $tipo( '2026/10/sumiu.jpg.webp' ) );

echo "Lixo e vazios\n";
confere( '.log', 'junk', $tipo( '2026/10/erro.log' ) );
confere( '.orig', 'junk', $tipo( '2026/10/foto.jpg.orig' ) );
confere( '.bak', 'junk', $tipo( '2026/10/x.bak' ) );
confere( '.tmp', 'junk', $tipo( '2026/10/x.tmp' ) );
confere( 'zero byte', 'empty', $tipo( '2026/10/falhou.jpg', 0 ) );

echo "O que nunca entra\n";
confere( 'index.php', null, $tipo( '2026/10/index.php' ) );
confere( '.htaccess', null, $tipo( '2026/10/.htaccess' ) );
confere( 'index.html', null, $tipo( 'index.html' ) );
confere( 'Thumbs.db', null, $tipo( '2026/10/Thumbs.db' ) );

echo "Escopo e chaves\n";
confere( 'pasta de ano entra', true, HMC_Orphans::top_dir_in_scope( '2026' ) );
confere( 'pasta de plugin fica de fora', false, HMC_Orphans::top_dir_in_scope( 'elementor' ) );
confere( 'woocommerce_uploads fica de fora', false, HMC_Orphans::top_dir_in_scope( 'woocommerce_uploads' ) );
confere( 'quarentena fica de fora', false, HMC_Orphans::top_dir_in_scope( 'hmc-quarantine' ) );
confere( '"2026x" não é ano', false, HMC_Orphans::top_dir_in_scope( '2026x' ) );
confere( 'chave ignora maiúsculas e barra inicial', HMC_Orphans::key( '2026/10/Foto.JPG' ), HMC_Orphans::key( '/2026/10/foto.jpg' ) );
confere( 'chaves de arquivos diferentes diferem', true, HMC_Orphans::key( 'a.jpg' ) !== HMC_Orphans::key( 'b.jpg' ) );

echo "\n$total verificações, $falhas falha(s).\n";
exit( $falhas ? 1 : 0 );
