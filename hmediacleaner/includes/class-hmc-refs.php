<?php
/**
 * Extrai referências a mídia (IDs de anexo e caminhos de arquivo) de qualquer texto:
 * HTML, blocos do editor, JSON (Elementor e outros construtores), dados serializados do PHP,
 * URLs completas, URLs de miniaturas e caminhos relativos ao diretório de uploads.
 *
 * Princípio: na dúvida, considerar como referência. Um falso "em uso" só deixa de liberar
 * espaço; um falso "sem uso" pode quebrar o site.
 */

defined( 'ABSPATH' ) || exit;

class HMC_Refs {

	const EXT = 'jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?|heic|pdf|mp4|m4v|mov|webm|mp3|m4a|ogg|wav|zip|docx?|xlsx?|pptx?|csv|txt|json|woff2?|ttf|otf';

	/** Trechos de nomes de campos que costumam guardar IDs de imagem. */
	const IMG_KEY = '(?:image|img|logo|icon|thumb|banner|background|bg_|photo|gallery|media|attachment|picture|avatar|cover|poster|foto|imagem|slide|file)';

	/** @var string Nome do diretório de uploads, já escapado para regex (normalmente "uploads"). */
	private $seg;

	public function __construct( $uploads_dirname = null ) {
		if ( null === $uploads_dirname ) {
			$up              = wp_upload_dir( null, false );
			$uploads_dirname = basename( untrailingslashit( $up['basedir'] ) );
		}
		$this->seg = preg_quote( $uploads_dirname, '#' );
	}

	/**
	 * Caminho relativo ao diretório de uploads, normalizado para comparação:
	 * minúsculas, sem sufixos de miniatura (-300x300), -scaled, -rotated, edições (-e123...)
	 * e cópias de segurança de compressão, e sem extensão .webp/.avif acrescentada por conversores.
	 */
	public static function normalize( $path ) {
		$p = strtolower( rawurldecode( trim( (string) $path ) ) );
		$p = ltrim( str_replace( '\\', '/', $p ), '/' );
		$p = preg_replace( '#\.(jpe?g|png|gif|bmp|tiff?)\.(webp|avif)$#', '.$1', $p );
		for ( $i = 0; $i < 3; $i++ ) {
			$n = preg_replace( '#-(?:\d{1,5}x\d{1,5}|scaled|rotated|e\d{10,}|updraft-pre-smush-original)(?=\.[a-z0-9]+$)#', '', $p );
			if ( $n === $p ) {
				break;
			}
			$p = $n;
		}
		return $p;
	}

	public static function file_key( $path ) {
		return md5( self::normalize( $path ) );
	}

	/**
	 * @param string $text      Conteúdo a analisar.
	 * @param string $key       Nome do campo/opção de onde veio o texto (ajuda a reconhecer IDs soltos).
	 * @param bool   $broad_ids Também considerar qualquer "id": N (conteúdo de blocos e construtores de página).
	 * @return array{0: int[], 1: string[]} IDs de anexo e caminhos normalizados.
	 */
	public function extract( $text, $key = '', $broad_ids = false ) {
		$ids   = array();
		$files = array();

		if ( ! is_string( $text ) || '' === $text ) {
			return array( $ids, $files );
		}

		$trim = trim( $text );
		if ( '' !== $key && preg_match( '#' . self::IMG_KEY . '#i', $key ) && preg_match( '/^\d+(?:\s*,\s*\d+)*$/', $trim ) ) {
			foreach ( preg_split( '/\s*,\s*/', $trim ) as $n ) {
				$ids[] = (int) $n;
			}
		}

		// JSON costuma escapar barras: "https:\/\/site\/wp-content\/uploads\/...".
		$t = ( false !== strpos( $text, '\\/' ) ) ? str_replace( '\\/', '/', $text ) : $text;

		if ( false !== strpos( $t, '.' ) ) {
			$stop = '[^\s"\'<>()\\\\?\#,;|*`]';
			// URLs e caminhos que passam pelo diretório de uploads.
			if ( preg_match_all( '#' . $this->seg . '/((?:' . $stop . '+/)*?' . str_replace( ']', '/]', $stop ) . '+?\.(?:' . self::EXT . '))(?![a-z0-9])#i', $t, $m ) ) {
				foreach ( $m[1] as $f ) {
					$files[] = self::normalize( $f );
				}
			}
			// Caminhos relativos no formato ano/mês/arquivo (como o WordPress guarda em _wp_attached_file).
			if ( preg_match_all( '#(?<![a-z0-9/._-])((?:19|20)\d{2}/[01]\d/' . str_replace( ']', '/]', $stop ) . '+?\.(?:' . self::EXT . '))(?![a-z0-9])#i', $t, $m ) ) {
				foreach ( $m[1] as $f ) {
					$files[] = self::normalize( $f );
				}
			}
		}

		if ( false !== stripos( $t, 'wp-image-' ) && preg_match_all( '/wp-image-(\d+)/i', $t, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/(?:data-id|data-attachment-id|attachment[_-]id|mediaId|imageId|image_id|thumbnail_id)["\']?\s*[=:]\s*["\']?(\d+)(?![\w-])/i', $t, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		// Galerias: [gallery ids="1,2,3"], blocos {"ids":[1,2,3]}.
		if ( preg_match_all( '/\bids["\']?\s*[=:]\s*["\'\[]?\s*([\d,\s]+)/i', $t, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, preg_split( '/[\s,]+/', trim( $list ) ) );
			}
		}
		// Dados serializados do PHP: s:8:"image_id";i:123;  ou  s:5:"image";s:3:"123";
		if ( preg_match_all( '/s:\d+:"[a-z0-9_\-]*' . self::IMG_KEY . '[a-z0-9_\-]*";(?:i:(\d+)|s:\d+:"([\d,\s]+)")/i', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $g ) {
				$ids = array_merge( $ids, preg_split( '/[\s,]+/', trim( '' !== $g[1] ? $g[1] : $g[2] ) ) );
			}
		}
		// JSON: "background_image": 123  ou  "logo": "123".
		if ( preg_match_all( '/"[a-z0-9_\-]*' . self::IMG_KEY . '[a-z0-9_\-]*"\s*:\s*(?:"([\d,\s]+)"|(\d+))/i', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $g ) {
				$ids = array_merge( $ids, preg_split( '/[\s,]+/', trim( '' !== $g[1] ? $g[1] : $g[2] ) ) );
			}
		}
		if ( $broad_ids && preg_match_all( '/"id"\s*:\s*"?(\d+)(?![\w-])/i', $t, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		return array( $ids, array_values( array_unique( array_filter( $files ) ) ) );
	}
}
