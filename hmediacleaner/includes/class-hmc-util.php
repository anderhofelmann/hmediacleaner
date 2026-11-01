<?php
/**
 * Funções de segurança do HMediaCleaner, sem dependência do WordPress (testáveis em linha de comando).
 *
 * Os caminhos de arquivo que o plugin move vêm do banco de dados (metadados do anexo, dados da
 * quarentena). Nada vindo de lá é confiável: um metadado adulterado com "../" faria o plugin
 * mexer em arquivos fora de uploads. Por isso todo caminho passa por aqui antes de tocar no disco.
 */

defined( 'ABSPATH' ) || exit;

class HMC_Util {

	/**
	 * Caminho relativo seguro (dentro do diretório de uploads) ou false.
	 *
	 * Recusa: vazio, longo demais, caracteres de controle, byte nulo, barra invertida, caminho
	 * absoluto, letra de unidade, URL com esquema e qualquer segmento "..".
	 *
	 * @param mixed $rel Caminho relativo vindo do banco.
	 * @return string|false Caminho normalizado (sem "//" nem "./") ou false.
	 */
	public static function safe_rel( $rel ) {
		if ( ! is_string( $rel ) || '' === $rel || strlen( $rel ) > 500 ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $rel ) ) {
			return false;
		}
		if ( '/' === $rel[0] || preg_match( '#^[a-zA-Z]:#', $rel ) || preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $rel ) ) {
			return false;
		}
		$out = array();
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return false;
			}
			$out[] = $part;
		}
		return $out ? implode( '/', $out ) : false;
	}

	/**
	 * Nome de arquivo simples (sem diretório), como os de miniaturas nos metadados.
	 *
	 * @param mixed $name Nome vindo do banco.
	 * @return string|false
	 */
	public static function safe_name( $name ) {
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 255 ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1f\x7f\\\\\/]/', $name ) || '.' === $name || '..' === $name ) {
			return false;
		}
		return $name;
	}

	/**
	 * O arquivo existente $path está, de fato (resolvendo links simbólicos), dentro de $root?
	 */
	public static function inside( $path, $root ) {
		$rp = realpath( $path );
		$rr = realpath( $root );
		if ( false === $rp || false === $rr ) {
			return false;
		}
		$rr = rtrim( $rr, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $rp, $rr ) && strlen( $rp ) > strlen( $rr );
	}

	/**
	 * A pasta existente $dir é $root ou está dentro dele (para conferir o destino de uma gravação).
	 */
	public static function dir_inside( $dir, $root ) {
		$rd = realpath( $dir );
		$rr = realpath( $root );
		if ( false === $rd || false === $rr ) {
			return false;
		}
		$rr = rtrim( $rr, DIRECTORY_SEPARATOR );
		return $rd === $rr || 0 === strpos( $rd, $rr . DIRECTORY_SEPARATOR );
	}

	/**
	 * Arquivo comum (não é link simbólico) que existe.
	 */
	public static function is_plain_file( $path ) {
		return is_file( $path ) && ! is_link( $path );
	}

	/**
	 * Tamanho em bytes de um arquivo comum, ou 0 se não existir. Uma única chamada ao sistema
	 * de arquivos (is_file + filesize seriam duas).
	 */
	public static function size( $path ) {
		$s = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $s ? 0 : (int) $s;
	}

	/**
	 * Neutraliza injeção de fórmula em planilhas: texto que começa com = + - @ (ou tabulação /
	 * retorno de carro) é lido como fórmula pelo Excel e pelo LibreOffice.
	 *
	 * @param mixed $v Valor da célula.
	 * @return string
	 */
	public static function csv_cell( $v ) {
		$v = (string) $v;
		if ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/**
	 * @param array $row Linha do CSV.
	 * @return array
	 */
	public static function csv_row( array $row ) {
		return array_map( array( __CLASS__, 'csv_cell' ), $row );
	}

	/**
	 * Desserializa sem instanciar nenhuma classe (evita injeção de objeto a partir de dados
	 * adulterados no banco). Valores que não são serializados voltam como estão.
	 *
	 * @param mixed $value Valor lido do banco.
	 * @return mixed
	 */
	public static function unser( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(?:[aObisdN]:|N;)/', $value ) ) {
			return $value;
		}
		$r = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions
		return false === $r && 'b:0;' !== $value ? $value : $r;
	}
}
