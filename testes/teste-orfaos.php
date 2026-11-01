<?php
/**
 * Testes da classificação de arquivos órfãos (não precisam de WordPress).
 *
 *   php testes/teste-orfaos.php
 */

define( 'ABSPATH', __DIR__ . '/' );
function __( $t, $d = '' ) { return $t; }
require __DIR__ . '/../hmediacleaner/includes/class-hmc-util.php';
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
$registrados = array( '2026/10/foto.jpg', '2026/10/foto-300x200.jpg', '2026/10/logo.png', 'logo.png', '2026/09/capa.jpeg', '2026/10/camera-scaled.jpg', '2026/10/iphone-scaled.jpeg' );
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

echo "Originais de imagens reduzidas (-scaled)\n";
confere( 'original grande de uma imagem -scaled registrada', 'original', $tipo( '2026/10/camera.jpg' ) );
confere( 'original com extensão .jpeg', 'original', $tipo( '2026/10/iphone.jpeg' ) );
confere( 'original que o site referencia não é candidato', 'in_use', HMC_Orphans::kind_for( '2026/10/camera.jpg', 5000, $conhecido, function ( $r ) { return true; } ) );
confere( 'miniatura do original (a base é a -scaled registrada)', 'thumb', $tipo( '2026/10/camera-300x225.jpg' ) );
confere( 'miniatura de original, em maiúsculas', 'thumb', $tipo( '2026/10/Camera-150x150.JPG' ) );
confere( 'original sem a versão -scaled registrada continua solto', 'loose', $tipo( '2026/10/sem-par.jpg' ) );

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

echo "Quarentena de órfãos: o que pode ser tocado\n";
confere( 'miniatura numa pasta de data', '2026/10/foto-300x300.jpg', HMC_Orphans::path_allowed( '2026/10/foto-300x300.jpg' ) );
confere( 'arquivo na raiz de uploads', 'solto.jpg', HMC_Orphans::path_allowed( 'solto.jpg' ) );
confere( 'normaliza // e ./', '2026/10/a.jpg', HMC_Orphans::path_allowed( '2026//10/./a.jpg' ) );
confere( 'recusa ../', false, HMC_Orphans::path_allowed( '2026/../../wp-config.php' ) );
confere( 'recusa caminho absoluto', false, HMC_Orphans::path_allowed( '/etc/passwd' ) );
confere( 'recusa a própria quarentena', false, HMC_Orphans::path_allowed( 'hmc-quarantine/orphans/a.jpg' ) );
confere( 'recusa a quarentena (maiúsculas)', false, HMC_Orphans::path_allowed( 'HMC-Quarantine/a.jpg' ) );
confere( 'recusa pasta de plugin (elementor)', false, HMC_Orphans::path_allowed( 'elementor/css/post-1.css' ) );
confere( 'recusa woocommerce_uploads', false, HMC_Orphans::path_allowed( 'woocommerce_uploads/x.pdf' ) );
confere( 'recusa .htaccess', false, HMC_Orphans::path_allowed( '2026/10/.htaccess' ) );
confere( 'recusa index.php', false, HMC_Orphans::path_allowed( '2026/10/index.php' ) );
confere( 'recusa arquivo oculto', false, HMC_Orphans::path_allowed( '2026/10/.segredo' ) );
confere( 'recusa vazio', false, HMC_Orphans::path_allowed( '' ) );
confere( 'recusa byte nulo', false, HMC_Orphans::path_allowed( "2026/10/a.jpg\0.php" ) );
confere( 'recusa não-texto', false, HMC_Orphans::path_allowed( array( '2026/a.jpg' ) ) );
confere( 'em uso nunca é movível', false, in_array( HMC_Orphans::IN_USE, HMC_Orphans::movable_kinds(), true ) );
confere( 'original é movível', true, in_array( HMC_Orphans::ORIGINAL, HMC_Orphans::movable_kinds(), true ) );
confere( 'original e solto exigem confirmação reforçada', true, in_array( HMC_Orphans::ORIGINAL, HMC_Orphans::sensitive_kinds(), true ) && in_array( HMC_Orphans::LOOSE, HMC_Orphans::sensitive_kinds(), true ) );

echo "Proteção de arquivos recentes\n";
$agora = 1800000000;
confere( 'modificado há 1 dia (proteção de 7) é recente', true, HMC_Orphans::is_recent( $agora - 86400, 7, $agora ) );
confere( 'modificado há 8 dias não é recente', false, HMC_Orphans::is_recent( $agora - 8 * 86400, 7, $agora ) );
confere( 'proteção 0 desliga', false, HMC_Orphans::is_recent( $agora, 0, $agora ) );

echo "Exclusão definitiva\n";
confere( 'dentro do prazo não exclui', false, HMC_Orphans::deletable( $agora - 29 * 86400, 0, 30, $agora ) );
confere( 'no prazo e sem acessos exclui', true, HMC_Orphans::deletable( $agora - 30 * 86400, 0, 30, $agora ) );
confere( 'com acessos nunca exclui', false, HMC_Orphans::deletable( $agora - 400 * 86400, 1, 30, $agora ) );
confere( 'prazo 0: exclui logo, mas só sem acessos', true, HMC_Orphans::deletable( $agora, 0, 0, $agora ) );
confere( 'prazo 0 com acesso não exclui', false, HMC_Orphans::deletable( $agora, 3, 0, $agora ) );

echo "\n$total verificações, $falhas falha(s).\n";
exit( $falhas ? 1 : 0 );
