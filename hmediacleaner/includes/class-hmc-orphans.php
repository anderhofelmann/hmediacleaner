<?php
/**
 * Arquivos órfãos: estão na pasta de uploads, mas nenhum anexo da biblioteca de mídia os registra
 * (miniaturas de tamanhos antigos, versões -scaled e editadas, cópias .orig e .webp, logs, arquivos
 * enviados por FTP...). Costumam ocupar mais espaço do que os anexos sem uso.
 *
 * Esta classe só CLASSIFICA e lista. Nada é movido nem apagado: é um relatório.
 *
 * A classificação não depende do WordPress, para ser testada em linha de comando.
 */

defined( 'ABSPATH' ) || exit;

class HMC_Orphans {

	const THUMB  = 'thumb';  // Miniatura (-300x300) de um tamanho que o anexo não registra.
	const SCALED = 'scaled'; // Versão -scaled sem registro.
	const EDIT   = 'edit';   // Versão editada (-e1700000000, -rotated).
	const WEBP   = 'webp';   // Cópia .webp/.avif de uma imagem que a biblioteca registra.
	const JUNK   = 'junk';   // .log, .bak, .orig, .tmp e cópias de segurança de compressão.
	const EMPTY_ = 'empty';  // Arquivo de zero byte.
	const LOOSE  = 'loose';  // Arquivo sem nenhum anexo e sem referência no site.
	const IN_USE = 'in_use'; // Sem anexo, mas o site o referencia: não é candidato.

	/** Nomes que o WordPress, servidores e sistemas criam de propósito. */
	const IGNORED = array( 'index.php', 'index.html', 'index.htm', '.htaccess', 'web.config', '.ds_store', 'thumbs.db', '.gitkeep', 'desktop.ini', 'robots.txt' );

	/** Sufixos que o WordPress e plugins acrescentam ao nome do arquivo original, e o tipo de órfão que isso indica. */
	private static function suffix_rules() {
		return array(
			'#-\d{1,5}x\d{1,5}(?=\.[a-z0-9]+$)#'               => self::THUMB,
			'#-scaled(?=\.[a-z0-9]+$)#'                         => self::SCALED,
			'#-e\d{10,}(?=\.[a-z0-9]+$)#'                       => self::EDIT,
			'#-rotated(?=\.[a-z0-9]+$)#'                        => self::EDIT,
			'#-updraft-pre-smush-original(?=\.[a-z0-9]+$)#'     => self::JUNK,
		);
	}

	/**
	 * Chave de comparação de um caminho relativo a uploads.
	 *
	 * @param string $rel Caminho relativo.
	 * @return string
	 */
	public static function key( $rel ) {
		return md5( strtolower( ltrim( str_replace( '\\', '/', (string) $rel ), '/' ) ) );
	}

	/**
	 * Tipo de órfão de um arquivo, ou null se não deve ser listado.
	 *
	 * @param string   $rel      Caminho relativo a uploads (com barras normais).
	 * @param int      $bytes    Tamanho do arquivo.
	 * @param callable $known    function( string $rel_minusculo ): bool – algum anexo registra esse caminho?
	 * @param callable $in_use   function( string $rel ): bool – o site referencia este arquivo?
	 * @return string|null
	 */
	public static function kind_for( $rel, $bytes, $known, $in_use ) {
		$rel   = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		$name  = strtolower( basename( $rel ) );
		if ( in_array( $name, self::IGNORED, true ) ) {
			return null;
		}
		if ( 0 === (int) $bytes ) {
			return self::EMPTY_;
		}
		if ( preg_match( '/\.(?:log|bak|old|orig|tmp|temp|swp)$/', $name ) ) {
			return self::JUNK;
		}

		$dir    = dirname( $rel );
		$prefix = ( '.' === $dir ) ? '' : strtolower( $dir ) . '/';
		$stem   = $name;
		$kind   = null;

		// Procura o arquivo "base" de que este é derivado, tirando sufixos conhecidos (até 3 níveis).
		for ( $i = 0; $i < 3; $i++ ) {
			$changed = false;
			foreach ( self::suffix_rules() as $rx => $k ) {
				$next = preg_replace( $rx, '', $stem, 1 );
				if ( null !== $next && $next !== $stem ) {
					$stem    = $next;
					$kind    = null === $kind ? $k : $kind;
					$changed = true;
				}
			}
			// Cópia .webp/.avif: foto.jpg.webp (acréscimo) ou foto.webp (troca de extensão).
			if ( preg_match( '/\.(?:webp|avif)$/', $stem ) ) {
				$sem = preg_replace( '/\.(?:webp|avif)$/', '', $stem );
				if ( preg_match( '/\.[a-z0-9]+$/', $sem ) && $known( $prefix . $sem ) ) {
					return self::WEBP;
				}
				foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $ext ) {
					if ( $known( $prefix . $sem . '.' . $ext ) ) {
						return self::WEBP;
					}
				}
			}
			if ( ! $changed ) {
				break;
			}
			if ( $known( $prefix . $stem ) ) {
				return $kind;
			}
		}

		if ( $known( $prefix . $stem ) && $stem !== $name ) {
			return $kind;
		}
		return $in_use( $rel ) ? self::IN_USE : self::LOOSE;
	}

	/**
	 * Rótulos e orientação de cada tipo.
	 *
	 * @return array tipo => array( rótulo, orientação )
	 */
	public static function kinds() {
		return array(
			self::THUMB  => array( __( 'Miniaturas antigas', 'hmediacleaner' ), __( 'Tamanhos de imagem que o anexo não usa mais. Podem ser apagadas, desde que nenhuma página aponte para o tamanho exato.', 'hmediacleaner' ) ),
			self::SCALED => array( __( 'Versões -scaled sem registro', 'hmediacleaner' ), __( 'Cópia reduzida de um arquivo que já existe sem ela.', 'hmediacleaner' ) ),
			self::EDIT   => array( __( 'Versões editadas', 'hmediacleaner' ), __( 'Restos de edição de imagem do WordPress (-e17...), sem registro.', 'hmediacleaner' ) ),
			self::WEBP   => array( __( 'Cópias WebP/AVIF de imagens da biblioteca', 'hmediacleaner' ), __( 'Geradas por um plugin de otimização. Só apague se nenhum plugin ou regra do servidor as entrega aos visitantes.', 'hmediacleaner' ) ),
			self::JUNK   => array( __( 'Logs, cópias de segurança e temporários', 'hmediacleaner' ), __( 'Arquivos .log, .bak, .orig, .tmp e cópias de compressão. Costumam ser seguros de apagar.', 'hmediacleaner' ) ),
			self::EMPTY_ => array( __( 'Arquivos vazios', 'hmediacleaner' ), __( 'Zero byte: uploads que falharam.', 'hmediacleaner' ) ),
			self::LOOSE  => array( __( 'Arquivos soltos', 'hmediacleaner' ), __( 'Sem anexo na biblioteca e sem referência no site. Confira antes de apagar (podem ter vindo de FTP ou de uma importação).', 'hmediacleaner' ) ),
			self::IN_USE => array( __( 'Soltos, mas em uso', 'hmediacleaner' ), __( 'Não estão na biblioteca, mas alguma página os referencia. Não são candidatos.', 'hmediacleaner' ) ),
		);
	}

	/**
	 * Pasta de uploads em que a procura de órfãos entra: só as de data (2026/10/) e a raiz.
	 * Pastas de plugins (elementor, woocommerce_uploads, wc-logs...) não são mídia e ficam de fora.
	 *
	 * @param string $segment Nome da pasta, no primeiro nível de uploads.
	 * @return bool
	 */
	public static function top_dir_in_scope( $segment ) {
		return (bool) preg_match( '/^(?:19|20)\d{2}$/', (string) $segment );
	}
}
