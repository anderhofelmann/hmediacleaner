<?php
// Monta o cenário de teste do HMediaCleaner. Rodar com: wp eval-file cenario.php
require_once ABSPATH . 'wp-admin/includes/image.php';

function hmc_t_img( $name, $rgb ) {
	$im = imagecreatetruecolor( 800, 800 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	imagestring( $im, 5, 20, 20, $name, imagecolorallocate( $im, 255, 255, 255 ) );
	$tmp = sys_get_temp_dir() . '/' . $name . '.png';
	imagepng( $im, $tmp );
	return $tmp;
}
function hmc_t_attach( $name, $rgb, $parent = 0, $tmp = null ) {
	$tmp  = $tmp ? $tmp : hmc_t_img( $name, $rgb );
	$up   = wp_upload_bits( $name . '.png', null, file_get_contents( $tmp ) );
	$id   = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ), $up['file'], $parent );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
	return $id;
}

$ids = array();
$p1  = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Produto 1', 'post_status' => 'publish' ) );
$p2  = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Produto 2', 'post_status' => 'publish' ) );

$ids['A_foto_produto'] = hmc_t_attach( 'a-foto', array( 200, 0, 0 ), $p1 );
set_post_thumbnail( $p1, $ids['A_foto_produto'] );

$ids['B_galeria'] = hmc_t_attach( 'b-galeria', array( 0, 200, 0 ), $p1 );
update_post_meta( $p1, '_product_image_gallery', (string) $ids['B_galeria'] );

$ids['C_conteudo_miniatura'] = hmc_t_attach( 'c-conteudo', array( 0, 0, 200 ) );
$c_url                       = str_replace( 'c-conteudo.png', 'c-conteudo-300x300.png', wp_get_attachment_url( $ids['C_conteudo_miniatura'] ) );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Sobre', 'post_status' => 'publish', 'post_content' => '<p>Texto</p><img src="' . $c_url . '" alt="">' ) );

$ids['D_categoria'] = hmc_t_attach( 'd-categoria', array( 200, 200, 0 ) );
$term               = wp_insert_term( 'Livros', 'product_cat' );
update_term_meta( $term['term_id'], 'thumbnail_id', $ids['D_categoria'] );

$ids['E_logo'] = hmc_t_attach( 'e-logo', array( 0, 200, 200 ) );
set_theme_mod( 'custom_logo', $ids['E_logo'] );

$ids['F_widget'] = hmc_t_attach( 'f-widget', array( 200, 0, 200 ) );
update_option( 'widget_text', array( 2 => array( 'title' => 'Rodapé', 'text' => '<img src="' . wp_get_attachment_url( $ids['F_widget'] ) . '">' ), '_multiwidget' => 1 ) );

$ids['G_arquivo_tema'] = hmc_t_attach( 'g-tema', array( 100, 100, 100 ) );
$rel                   = get_post_meta( $ids['G_arquivo_tema'], '_wp_attached_file', true );
file_put_contents( get_stylesheet_directory() . '/style.css', "\n/* teste */ .rodape { background: url(../../uploads/{$rel}); }\n", FILE_APPEND );

$ids['H_elementor'] = hmc_t_attach( 'h-elementor', array( 50, 150, 250 ) );
$page               = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Landing', 'post_status' => 'publish' ) );
update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => '3f2a1b', 'settings' => array( 'image' => array( 'url' => wp_get_attachment_url( $ids['H_elementor'] ), 'id' => $ids['H_elementor'] ) ) ) ) ) ) );

$ids['I_anexada_sem_uso'] = hmc_t_attach( 'i-anexada', array( 250, 120, 0 ), $p2 );
$ids['J_orfa']            = hmc_t_attach( 'j-orfa', array( 30, 30, 30 ) );
$ids['K_duplicata_de_J']  = hmc_t_attach( 'k-copia', null, 0, sys_get_temp_dir() . '/j-orfa.png' );

wp_mkdir_p( WPMU_PLUGIN_DIR );
$ids['L_so_no_html_final'] = hmc_t_attach( 'l-frontend', array( 10, 250, 10 ) );
update_option( 'hmc_test_frontend_id', $ids['L_so_no_html_final'] ); // opções hmc_* não são lidas: só a visita à página encontra.
file_put_contents( WPMU_PLUGIN_DIR . '/teste-rodape.php', "<?php\nadd_action( 'wp_footer', function () { echo '<img src=\"' . esc_url( wp_get_attachment_url( (int) get_option( 'hmc_test_frontend_id' ) ) ) . '\">'; } );\n" );

$ids['M_recente'] = hmc_t_attach( 'm-recente', array( 90, 0, 90 ) );

// Tudo com 30 dias, menos a recente.
global $wpdb;
foreach ( $ids as $k => $id ) {
	if ( 'M_recente' !== $k ) {
		$wpdb->update( $wpdb->posts, array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'ID' => $id ) );
	}
}
update_option( 'hmc_test_ids', $ids );
foreach ( $ids as $k => $id ) {
	echo str_pad( $k, 24 ) . $id . "\n";
}
