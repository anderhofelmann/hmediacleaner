<?php
// Teste isolado do extrator (sem WordPress). Uso: php teste-extrator.php
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../hmediacleaner/includes/class-hmc-refs.php';

$r     = new HMC_Refs( 'uploads' );
$falhas = 0;

function confere( $nome, $obtido, $esperado ) {
	global $falhas;
	sort( $obtido );
	sort( $esperado );
	$ok = ( $obtido == $esperado );
	if ( ! $ok ) {
		$falhas++;
	}
	printf( "%s %s\n", $ok ? 'OK   ' : 'FALHA', $nome );
	if ( ! $ok ) {
		echo '   obtido:   ' . json_encode( $obtido ) . "\n   esperado: " . json_encode( $esperado ) . "\n";
	}
}

// Normalização
confere( 'miniatura', array( HMC_Refs::normalize( '2026/10/Livro-300x300.JPG' ) ), array( '2026/10/livro.jpg' ) );
confere( 'scaled', array( HMC_Refs::normalize( '2026/10/foto-scaled.jpg' ) ), array( '2026/10/foto.jpg' ) );
confere( 'webp convertido', array( HMC_Refs::normalize( '2026/10/foto-768x768.jpg.webp' ) ), array( '2026/10/foto.jpg' ) );
confere( 'cópia -1 continua distinta', array( HMC_Refs::normalize( '2026/10/7793273007977-1.jpg' ) ), array( '2026/10/7793273007977-1.jpg' ) );
confere( 'url codificada', array( HMC_Refs::normalize( '2024/05/minha%20foto.png' ) ), array( '2024/05/minha foto.png' ) );

// HTML clássico com srcset
list( $ids, $files ) = $r->extract( '<img class="wp-image-123 size-full" src="https://loja.com/wp-content/uploads/2026/03/9788538065579-350x350.jpg" srcset="https://loja.com/wp-content/uploads/2026/03/9788538065579-300x300.jpg 300w, https://loja.com/wp-content/uploads/2026/03/outra.webp 600w">' );
confere( 'html ids', $ids, array( 123 ) );
confere( 'html arquivos', $files, array( '2026/03/9788538065579.jpg', '2026/03/outra.webp' ) );

// Bloco do editor
list( $ids, $files ) = $r->extract( '<!-- wp:image {"id":456,"sizeSlug":"large"} --><figure><img src="/loja/wp-content/uploads/2025/01/banner.png" class="wp-image-456"/></figure><!-- /wp:image --><!-- wp:gallery {"ids":[7,8,9]} -->', '', true );
confere( 'bloco ids', $ids, array( 456, 7, 8, 9 ) );
confere( 'bloco arquivos', $files, array( '2025/01/banner.png' ) );

// Elementor (JSON com barras escapadas)
list( $ids, $files ) = $r->extract( '[{"id":"3f2a1b","elements":[{"settings":{"image":{"url":"https:\/\/site.com\/wp-content\/uploads\/2024\/02\/topo-1024x400.jpg","id":789},"background_image":{"url":"","id":"790"}}}]}]', '_elementor_data', true );
confere( 'elementor ids', $ids, array( 789, 790 ) );
confere( 'elementor arquivos', $files, array( '2024/02/topo.jpg' ) );

// Serializado (theme_mods, widgets)
list( $ids, $files ) = $r->extract( serialize( array( 'custom_logo' => 55, 'header_image' => 'https://site.com/wp-content/uploads/2023/07/cabecalho.png', 'nav_menu_locations' => array( 'primary' => 12 ) ) ), 'theme_mods_retail' );
confere( 'serializado ids (não pega menu)', $ids, array( 55 ) );
confere( 'serializado arquivos', $files, array( '2023/07/cabecalho.png' ) );

// Campos do WooCommerce
list( $ids ) = $r->extract( '101,102, 103', '_product_image_gallery' );
confere( 'galeria de produto', $ids, array( 101, 102, 103 ) );
list( $ids ) = $r->extract( '321', '_thumbnail_id' );
confere( 'foto principal', $ids, array( 321 ) );
list( $ids ) = $r->extract( '999', '_stock' );
confere( 'número sem relação (estoque)', $ids, array() );

// Shortcode de galeria e caminho relativo
list( $ids, $files ) = $r->extract( '[gallery ids="11,12"] veja 2022/09/manual.pdf' );
confere( 'shortcode ids', $ids, array( 11, 12 ) );
confere( 'caminho relativo', $files, array( '2022/09/manual.pdf' ) );

// Sem falsos positivos óbvios
list( $ids, $files ) = $r->extract( 'Pedido 2026 entregue em 10/10. Total R$ 120,00. Versão 1.2.3 do arquivo config.php' );
confere( 'texto comum: ids', $ids, array() );
confere( 'texto comum: arquivos', $files, array() );

echo $falhas ? "\n{$falhas} falha(s)\n" : "\nTodos os testes passaram.\n";
exit( $falhas ? 1 : 0 );
