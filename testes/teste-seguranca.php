<?php
/**
 * Testes das proteções de segurança do HMediaCleaner (não precisam de WordPress).
 *
 *   php testes/teste-seguranca.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../hmediacleaner/includes/class-hmc-util.php';

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

echo "Caminhos relativos (safe_rel)\n";
confere( 'caminho normal', '2026/10/foto.jpg', HMC_Util::safe_rel( '2026/10/foto.jpg' ) );
confere( 'arquivo na raiz', 'logo.png', HMC_Util::safe_rel( 'logo.png' ) );
confere( 'colapsa // e ./', '2026/10/foto.jpg', HMC_Util::safe_rel( '2026//10/./foto.jpg' ) );
confere( 'pontos no meio do nome são válidos', '2026/10/foto..jpg', HMC_Util::safe_rel( '2026/10/foto..jpg' ) );
confere( 'recusa ../ no começo', false, HMC_Util::safe_rel( '../wp-config.php' ) );
confere( 'recusa ../ no meio', false, HMC_Util::safe_rel( '2026/../../wp-config.php' ) );
confere( 'recusa ../ no fim', false, HMC_Util::safe_rel( '2026/10/..' ) );
confere( 'recusa só ..', false, HMC_Util::safe_rel( '..' ) );
confere( 'recusa caminho absoluto', false, HMC_Util::safe_rel( '/etc/passwd' ) );
confere( 'recusa letra de unidade', false, HMC_Util::safe_rel( 'C:/Windows/x.jpg' ) );
confere( 'recusa URL', false, HMC_Util::safe_rel( 'https://exemplo.test/a.jpg' ) );
confere( 'recusa barra invertida', false, HMC_Util::safe_rel( '2026\\10\\..\\x.jpg' ) );
confere( 'recusa byte nulo', false, HMC_Util::safe_rel( "2026/10/foto.jpg\0.php" ) );
confere( 'recusa caractere de controle', false, HMC_Util::safe_rel( "2026/10/fo\nto.jpg" ) );
confere( 'recusa vazio', false, HMC_Util::safe_rel( '' ) );
confere( 'recusa só pontos e barras', false, HMC_Util::safe_rel( './/.' ) );
confere( 'recusa muito longo', false, HMC_Util::safe_rel( str_repeat( 'a/', 300 ) . 'x.jpg' ) );
confere( 'recusa tipo errado', false, HMC_Util::safe_rel( array( '2026/10/a.jpg' ) ) );

echo "Nomes de arquivo (safe_name)\n";
confere( 'miniatura', 'foto-300x300.jpg', HMC_Util::safe_name( 'foto-300x300.jpg' ) );
confere( 'recusa barra', false, HMC_Util::safe_name( 'a/b.jpg' ) );
confere( 'recusa ../', false, HMC_Util::safe_name( '../x.jpg' ) );
confere( 'recusa ..', false, HMC_Util::safe_name( '..' ) );
confere( 'recusa .', false, HMC_Util::safe_name( '.' ) );
confere( 'recusa byte nulo', false, HMC_Util::safe_name( "a\0.jpg" ) );
confere( 'recusa vazio', false, HMC_Util::safe_name( '' ) );

echo "Contenção em disco (links simbólicos)\n";
$tmp = sys_get_temp_dir() . '/hmc-teste-' . getmypid();
@mkdir( $tmp . '/raiz/2026', 0777, true );
@mkdir( $tmp . '/fora', 0777, true );
file_put_contents( $tmp . '/raiz/2026/a.jpg', 'x' );
file_put_contents( $tmp . '/fora/segredo.txt', 'segredo' );
$link_ok = @symlink( $tmp . '/fora/segredo.txt', $tmp . '/raiz/2026/atalho.jpg' );
confere( 'arquivo dentro da raiz', true, HMC_Util::inside( $tmp . '/raiz/2026/a.jpg', $tmp . '/raiz' ) );
confere( 'a própria raiz não conta como arquivo dentro', false, HMC_Util::inside( $tmp . '/raiz', $tmp . '/raiz' ) );
confere( 'arquivo fora da raiz', false, HMC_Util::inside( $tmp . '/fora/segredo.txt', $tmp . '/raiz' ) );
confere( 'caminho com ../ que escapa', false, HMC_Util::inside( $tmp . '/raiz/../fora/segredo.txt', $tmp . '/raiz' ) );
confere( 'arquivo inexistente', false, HMC_Util::inside( $tmp . '/raiz/nao-existe.jpg', $tmp . '/raiz' ) );
if ( $link_ok ) {
	confere( 'link simbólico que aponta para fora é recusado', false, HMC_Util::inside( $tmp . '/raiz/2026/atalho.jpg', $tmp . '/raiz' ) );
	confere( 'link simbólico não é arquivo comum', false, HMC_Util::is_plain_file( $tmp . '/raiz/2026/atalho.jpg' ) );
}
confere( 'arquivo comum', true, HMC_Util::is_plain_file( $tmp . '/raiz/2026/a.jpg' ) );
confere( 'pasta dentro da raiz', true, HMC_Util::dir_inside( $tmp . '/raiz/2026', $tmp . '/raiz' ) );
confere( 'a raiz é aceita como destino', true, HMC_Util::dir_inside( $tmp . '/raiz', $tmp . '/raiz' ) );
confere( 'pasta fora da raiz', false, HMC_Util::dir_inside( $tmp . '/fora', $tmp . '/raiz' ) );
confere( 'pasta irmã com prefixo igual não engana', false, HMC_Util::dir_inside( $tmp . '/raiz-outra', $tmp . '/raiz' ) );
confere( 'tamanho', 1, HMC_Util::size( $tmp . '/raiz/2026/a.jpg' ) );
confere( 'tamanho de arquivo inexistente', 0, HMC_Util::size( $tmp . '/raiz/nao.jpg' ) );
@unlink( $tmp . '/raiz/2026/atalho.jpg' );
@unlink( $tmp . '/raiz/2026/a.jpg' );
@unlink( $tmp . '/fora/segredo.txt' );
@rmdir( $tmp . '/raiz/2026' );
@rmdir( $tmp . '/raiz' );
@rmdir( $tmp . '/fora' );
@rmdir( $tmp );

echo "CSV (injeção de fórmula)\n";
confere( 'texto comum fica igual', 'foto.jpg', HMC_Util::csv_cell( 'foto.jpg' ) );
confere( '= vira texto', "'=cmd|' /C calc'!A0", HMC_Util::csv_cell( "=cmd|' /C calc'!A0" ) );
confere( '+ vira texto', "'+1+1", HMC_Util::csv_cell( '+1+1' ) );
confere( '- vira texto', "'-2+3", HMC_Util::csv_cell( '-2+3' ) );
confere( '@ vira texto', "'@SUM(A1)", HMC_Util::csv_cell( '@SUM(A1)' ) );
confere( 'tabulação vira texto', "'\tx", HMC_Util::csv_cell( "\tx" ) );
confere( 'vazio fica vazio', '', HMC_Util::csv_cell( '' ) );
confere( 'número vira texto sem alterar', '123', HMC_Util::csv_cell( 123 ) );
confere( 'linha inteira', array( '12', "'=x", 'ok' ), HMC_Util::csv_row( array( 12, '=x', 'ok' ) ) );

echo "Desserialização segura\n";
confere( 'array serializado', array( 'a' => 1 ), HMC_Util::unser( 'a:1:{s:1:"a";i:1;}' ) );
confere( 'array já pronto', array( 'x' ), HMC_Util::unser( array( 'x' ) ) );
confere( 'texto comum fica igual', 'texto', HMC_Util::unser( 'texto' ) );
confere( 'false serializado', false, HMC_Util::unser( 'b:0;' ) );
confere( 'serializado corrompido fica como texto', 'a:1:{s:1:"a";', HMC_Util::unser( 'a:1:{s:1:"a";' ) );
class HMC_Teste_Perigosa {
	public $x = 1;
	public function __wakeup() {
		$GLOBALS['hmc_wakeup'] = true;
	}
}
$obj = HMC_Util::unser( serialize( new HMC_Teste_Perigosa() ) );
confere( 'objeto não é instanciado', false, $obj instanceof HMC_Teste_Perigosa );
confere( '__wakeup não é executado', false, isset( $GLOBALS['hmc_wakeup'] ) );

echo "\n$total verificações, $falhas falha(s).\n";
exit( $falhas ? 1 : 0 );
