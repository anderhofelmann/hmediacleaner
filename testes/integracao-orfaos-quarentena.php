<?php
/**
 * Testes de integração da quarentena de arquivos órfãos. Rodar SOMENTE num WordPress DESCARTÁVEL:
 * o teste cria e move arquivos de verdade e tenta, de propósito, ataques de caminho e de link simbólico.
 *
 *   wp eval-file testes/integracao-orfaos-quarentena.php
 *
 * Cobre: mover, restaurar, excluir; recusas (em uso, recente, mudou desde a análise, passou a ser
 * registrado, link simbólico, "../", pasta de plugin); contagem de acessos; prazo da exclusão;
 * dados da quarentena adulterados; restauração em massa (desinstalação).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb, $base;
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

/** Texto de recusa (qualquer string) conta como "recusado". */
function hmc_recusa( $res ) {
	return is_string( $res );
}

function hmc_scan() {
	$sc = new HMC_Scanner();
	$sc->start();
	do {
		$st = $sc->run( 30 );
	} while ( empty( $st['finished'] ) );
}

$tag  = 'oq' . substr( md5( microtime() ), 0, 6 );
$up   = wp_upload_dir( null, false );
$base = trailingslashit( $up['basedir'] );
$mes  = '2020/01/';
$qdir = $base . 'hmc-quarantine/orphans/';
wp_mkdir_p( $base . $mes );
$velho = time() - 30 * DAY_IN_SECONDS;

/** Cria um arquivo com data de modificação definida. */
function hmc_f( $rel, $conteudo, $mtime ) {
	global $base;
	wp_mkdir_p( dirname( $base . $rel ) );
	file_put_contents( $base . $rel, $conteudo );
	touch( $base . $rel, $mtime );
}

/** Anexo "cru" (o arquivo existe e o anexo o registra). */
function hmc_anexo( $rel, $conteudo, $mtime ) {
	global $wpdb, $base;
	hmc_f( $rel, $conteudo, $mtime );
	$id  = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => basename( $rel ), 'post_status' => 'inherit' ), $base . $rel );
	update_post_meta( $id, '_wp_attached_file', $rel );
	$old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
	$wpdb->update( $wpdb->posts, array( 'post_date' => $old, 'post_date_gmt' => $old ), array( 'ID' => $id ) );
	return (int) $id;
}

/* ====================================================================== preparação */

hmc_anexo( "{$mes}$tag-foto.png", random_bytes( 3000 ), $velho );
hmc_anexo( "{$mes}$tag-camera-scaled.png", random_bytes( 3000 ), $velho );

$miniatura = "{$mes}$tag-foto-300x300.png";   // Miniatura órfã, antiga.
$lixo      = "{$mes}$tag-erro.log";             // Lixo antigo.
$lixo2     = "{$mes}$tag-erro2.log";            // Outro lixo (para o teste de exclusão).
$recente   = "{$mes}$tag-novo.log";             // Lixo, mas modificado agora.
$original  = "{$mes}$tag-camera.png";           // Original grande (o anexo usa a -scaled).
$solto     = "{$mes}$tag-solto.png";            // Solto, antigo.
$usado     = "{$mes}$tag-usado.png";            // Solto, mas uma página o usa.
$mudara    = "{$mes}$tag-mudara.log";           // Vai mudar depois da análise.
$vira      = "{$mes}$tag-virara.png";           // Vai virar anexo depois da análise.
$plugin    = "$tag-plugin/x.css";               // Pasta de plugin: fora do escopo.
hmc_f( $miniatura, 'miniatura', $velho );
hmc_f( $lixo, 'log antigo', $velho );
hmc_f( $lixo2, 'outro log', $velho );
hmc_f( $recente, 'log novo', time() );
hmc_f( $original, random_bytes( 5000 ), $velho );
hmc_f( $solto, 'solto', $velho );
hmc_f( $usado, 'usado', $velho );
hmc_f( $mudara, 'antes', $velho );
hmc_f( $vira, 'sera anexo', $velho );
hmc_f( $plugin, 'css', $velho );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => "Usa $tag", 'post_status' => 'publish', 'post_content' => '<img src="' . trailingslashit( $up['baseurl'] ) . $usado . '">' ) );

// Um alvo fora de uploads e um link simbólico para ele dentro de uploads.
$alvo = WP_CONTENT_DIR . "/$tag-alvo-do-link.txt";
file_put_contents( $alvo, 'nao-pode-ser-tocado' );
@symlink( $alvo, $base . "{$mes}$tag-link.png" );
// Sentinela fora de uploads, para provar que nada escapa.
$sentinela = WP_CONTENT_DIR . "/$tag-sentinela.txt";
file_put_contents( $sentinela, 'intacto' );

hmc_scan();
$o  = HMC_DB::orphans_table();
$oq = HMC_DB::oq_table();
$tipo = function ( $rel ) use ( $wpdb, $o ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT kind FROM {$o} WHERE path_key = %s", HMC_Orphans::key( $rel ) ) );
};
$qrow = function ( $rel ) use ( $wpdb, $oq ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$oq} WHERE path_key = %s", HMC_Orphans::key( $rel ) ) );
};

echo "Classificação da análise\n";
hmc_ok( 'miniatura órfã', 'thumb', $tipo( $miniatura ) );
hmc_ok( 'log é lixo', 'junk', $tipo( $lixo ) );
hmc_ok( 'original grande', 'original', $tipo( $original ) );
hmc_ok( 'arquivo solto', 'loose', $tipo( $solto ) );
hmc_ok( 'solto que uma página usa', 'in_use', $tipo( $usado ) );
hmc_ok( 'pasta de plugin não é listada', null, $tipo( $plugin ) );
hmc_ok( 'link simbólico não é listado', null, $tipo( "{$mes}$tag-link.png" ) );

echo "Mover para a quarentena\n";
hmc_ok( 'miniatura vai para a quarentena', true, HMC_Orphan_Quarantine::quarantine( $miniatura ) );
hmc_ok( 'saiu do lugar de origem', false, file_exists( $base . $miniatura ) );
hmc_ok( 'está na pasta de quarentena, com o mesmo caminho', 'miniatura', @file_get_contents( $qdir . $miniatura ) );
$r = $qrow( $miniatura );
hmc_ok( 'registrada como em quarentena', 'quarantined', $r ? $r->q_status : null );
hmc_ok( 'guarda o tipo', 'thumb', $r ? $r->kind : null );
hmc_ok( 'guarda o tamanho', strlen( 'miniatura' ), $r ? (int) $r->bytes : null );
hmc_ok( 'sai da lista de órfãos do relatório', null, $tipo( $miniatura ) );
hmc_ok( 'original grande vai para a quarentena', true, HMC_Orphan_Quarantine::quarantine( $original ) );
hmc_ok( 'arquivo solto vai para a quarentena', true, HMC_Orphan_Quarantine::quarantine( $solto ) );
hmc_ok( 'já está em quarentena: nova tentativa é recusada', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( $miniatura ) ) );

echo "Recusas de segurança ao mover\n";
hmc_ok( '"em uso" nunca é movido', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( $usado ) ) );
hmc_ok( '"em uso" continua no lugar', true, file_exists( $base . $usado ) );
hmc_ok( 'modificado agora é protegido', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( $recente ) ) );
hmc_ok( 'protegido continua no lugar', true, file_exists( $base . $recente ) );
file_put_contents( $base . $mudara, 'depois, com outro tamanho' );
touch( $base . $mudara, $velho );
hmc_ok( 'mudou de tamanho desde a análise: recusado', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( $mudara ) ) );
hmc_ok( 'o que mudou continua no lugar', true, file_exists( $base . $mudara ) );
// Um anexo passa a registrar o arquivo depois da análise.
$id_vira = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'vira', 'post_status' => 'inherit' ), $base . $vira );
update_post_meta( $id_vira, '_wp_attached_file', $vira );
hmc_ok( 'passou a ser registrado pela biblioteca: recusado', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( $vira ) ) );
hmc_ok( 'registrado continua no lugar', true, file_exists( $base . $vira ) );
hmc_ok( 'fora de uploads (../)', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( "../$tag-sentinela.txt" ) ) );
hmc_ok( 'fora de uploads (caminho absoluto)', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( $sentinela ) ) );
hmc_ok( 'pasta de plugin é recusada', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( $plugin ) ) );
hmc_ok( 'arquivo que não consta na análise é recusado', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( "{$mes}$tag-nao-listado.png" ) ) );
// Linha de relatório adulterada apontando para um link simbólico.
$wpdb->replace( $o, array( 'path_key' => HMC_Orphans::key( "{$mes}$tag-link.png" ), 'path' => "{$mes}$tag-link.png", 'bytes' => strlen( 'nao-pode-ser-tocado' ), 'mtime' => gmdate( 'Y-m-d H:i:s', $velho ), 'kind' => 'loose' ) );
hmc_ok( 'link simbólico é recusado', true, hmc_recusa( HMC_Orphan_Quarantine::quarantine( "{$mes}$tag-link.png" ) ) );
hmc_ok( 'alvo do link intacto', 'nao-pode-ser-tocado', file_get_contents( $alvo ) );
hmc_ok( 'sentinela fora de uploads intacta', 'intacto', file_get_contents( $sentinela ) );

echo "Restaurar\n";
hmc_ok( 'restaura a miniatura', true, HMC_Orphan_Quarantine::restore( $miniatura ) );
hmc_ok( 'voltou ao lugar de origem', 'miniatura', @file_get_contents( $base . $miniatura ) );
hmc_ok( 'saiu da quarentena', false, file_exists( $qdir . $miniatura ) );
$r = $qrow( $miniatura );
hmc_ok( 'registrada como restaurada', 'restored', $r ? $r->q_status : null );
hmc_ok( 'restaurar de novo é recusado (não está em quarentena)', true, hmc_recusa( HMC_Orphan_Quarantine::restore( $miniatura ) ) );
// Nunca sobrescreve: o solto está em quarentena e alguém criou outro arquivo no lugar dele.
file_put_contents( $base . $solto, 'arquivo novo no mesmo lugar' );
hmc_ok( 'não sobrescreve arquivo existente', true, hmc_recusa( HMC_Orphan_Quarantine::restore( $solto ) ) );
hmc_ok( 'o arquivo novo não foi tocado', 'arquivo novo no mesmo lugar', file_get_contents( $base . $solto ) );
hmc_ok( 'o da quarentena continua guardado', 'solto', @file_get_contents( $qdir . $solto ) );
unlink( $base . $solto );
hmc_ok( 'depois de liberar o lugar, restaura', true, HMC_Orphan_Quarantine::restore( $solto ) );
hmc_ok( 'o solto voltou com o conteúdo original', 'solto', file_get_contents( $base . $solto ) );

echo "Acessos enquanto em quarentena\n";
hmc_ok( 'move de novo a miniatura', true, HMC_Orphan_Quarantine::quarantine( $miniatura ) );
HMC_Orphan_Quarantine::track_404( $miniatura );
$r = $qrow( $miniatura );
hmc_ok( 'um pedido ao endereço original é contado', 1, $r ? (int) $r->q_hits : null );
HMC_Orphan_Quarantine::track_404( $miniatura );
$r = $qrow( $miniatura );
hmc_ok( 'pedidos repetidos são limitados (uma escrita por 5 min)', 1, $r ? (int) $r->q_hits : null );
HMC_Orphan_Quarantine::track_404( "{$mes}$tag-outro.png" );
hmc_ok( 'pedido a endereço desconhecido não cria nada', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$oq} WHERE path = %s", "{$mes}$tag-outro.png" ) ) );

echo "Exclusão definitiva\n";
hmc_ok( 'move o lixo para a quarentena', true, HMC_Orphan_Quarantine::quarantine( $lixo ) );
hmc_ok( 'dentro do prazo não exclui', true, hmc_recusa( HMC_Orphan_Quarantine::delete( $lixo, 30 ) ) );
hmc_ok( 'o arquivo continua guardado', true, file_exists( $qdir . $lixo ) );
$wpdb->update( $oq, array( 'q_date' => wp_date( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'path_key' => HMC_Orphans::key( $lixo ) ) );
hmc_ok( 'passado o prazo, sem acessos: exclui', true, HMC_Orphan_Quarantine::delete( $lixo, 30 ) );
hmc_ok( 'o arquivo foi removido da quarentena', false, file_exists( $qdir . $lixo ) );
$r = $qrow( $lixo );
hmc_ok( 'registrado como excluído', 'deleted', $r ? $r->q_status : null );
hmc_ok( 'excluir de novo é recusado', true, hmc_recusa( HMC_Orphan_Quarantine::delete( $lixo, 30 ) ) );
// Com acessos: nunca exclui, mesmo passado o prazo.
$wpdb->update( $oq, array( 'q_date' => wp_date( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'path_key' => HMC_Orphans::key( $miniatura ) ) );
hmc_ok( 'com acessos nunca exclui, mesmo depois do prazo', true, hmc_recusa( HMC_Orphan_Quarantine::delete( $miniatura, 30 ) ) );
hmc_ok( 'o arquivo com acessos continua guardado', true, file_exists( $qdir . $miniatura ) );
hmc_ok( 'exclusão de arquivo que não está em quarentena é recusada', true, hmc_recusa( HMC_Orphan_Quarantine::delete( $usado, 0 ) ) );
hmc_ok( 'o arquivo em uso não foi tocado', true, file_exists( $base . $usado ) );

echo "Dados da quarentena adulterados\n";
$wpdb->insert( $oq, array( 'path_key' => md5( 'adulterado1' ), 'fkey' => '', 'path' => "../$tag-sentinela.txt", 'kind' => 'loose', 'bytes' => 7, 'q_status' => 'quarantined', 'q_date' => '2000-01-01 00:00:00', 'q_hits' => 0 ) );
$wpdb->insert( $oq, array( 'path_key' => md5( 'adulterado2' ), 'fkey' => '', 'path' => '/etc/hostname', 'kind' => 'loose', 'bytes' => 7, 'q_status' => 'quarantined', 'q_date' => '2000-01-01 00:00:00', 'q_hits' => 0 ) );
hmc_ok( 'restauração com "../" é recusada', true, hmc_recusa( HMC_Orphan_Quarantine::restore( "../$tag-sentinela.txt" ) ) );
hmc_ok( 'exclusão com "../" é recusada', true, hmc_recusa( HMC_Orphan_Quarantine::delete( "../$tag-sentinela.txt", 0 ) ) );
hmc_ok( 'caminho absoluto é recusado', true, hmc_recusa( HMC_Orphan_Quarantine::delete( '/etc/hostname', 0 ) ) );
hmc_ok( 'sentinela continua intacta', 'intacto', file_get_contents( $sentinela ) );
$wpdb->query( "DELETE FROM {$oq} WHERE path_key IN ('" . md5( 'adulterado1' ) . "','" . md5( 'adulterado2' ) . "')" );

echo "Lotes e resumo\n";
list( $tab, $cond ) = HMC_Orphan_Quarantine::selection( 'quarantine', 'junk' );
hmc_ok( 'seleção de quarentena usa a tabela do relatório', $o, $tab );
$qtd = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tab} WHERE {$cond}" );
hmc_ok( 'seleção por tipo acha os logs restantes (inclui o recente)', true, $qtd >= 3 );
$caminhos = $wpdb->get_col( "SELECT path FROM {$tab} WHERE {$cond} AND path LIKE '%{$tag}%'" );
list( $ok, $ign ) = HMC_Orphan_Quarantine::run_batch( 'quarantine', $caminhos );
hmc_ok( 'lote move só o elegível (1 log antigo e inalterado)', 1, $ok );
hmc_ok( 'lote ignora o recente e o que mudou desde a análise', 2, $ign );
hmc_ok( 'o recente continua no lugar após o lote', true, file_exists( $base . $recente ) );
$res = HMC_Orphan_Quarantine::summary();
hmc_ok( 'resumo conta o que está em quarentena', true, ! empty( $res['thumb'] ) && $res['thumb']['count'] >= 1 );
hmc_ok( 'resumo conta o arquivo com acesso', true, ! empty( $res['thumb'] ) && $res['thumb']['hits'] >= 1 );
hmc_ok( 'a miniatura deste teste está em quarentena e com 1 acesso', array( 'quarantined', 1 ), array( $qrow( $miniatura )->q_status, (int) $qrow( $miniatura )->q_hits ) );
hmc_ok( 'in_use não aparece nas seleções de quarentena', 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE kind = 'in_use' AND " . HMC_Orphan_Quarantine::selection( 'quarantine', '' )[1] ) );

echo "Restauração em massa (desinstalação)\n";
list( $rest, $falhas ) = HMC_Orphan_Quarantine::restore_all();
hmc_ok( 'restaura tudo sem falhas', 0, $falhas );
hmc_ok( 'a miniatura voltou', true, file_exists( $base . $miniatura ) );
hmc_ok( 'o original grande voltou', true, file_exists( $base . $original ) );
hmc_ok( 'nada resta em quarentena', array(), HMC_Orphan_Quarantine::summary() );
hmc_ok( 'a pasta de órfãos da quarentena ficou sem arquivos', array(), array_values( array_filter( (array) glob( $qdir . '*/*/*' ) ) ) );

echo "\n{$GLOBALS['hmc_total']} verificações, {$GLOBALS['hmc_falhas']} falha(s).\n";
exit( $GLOBALS['hmc_falhas'] ? 1 : 0 );
