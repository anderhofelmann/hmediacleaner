<?php
/**
 * Arquivos órfãos: estão na pasta de uploads, mas nenhum anexo da biblioteca de mídia os registra
 * (miniaturas de tamanhos antigos, versões -scaled e editadas, cópias .orig e .webp, logs, arquivos
 * enviados por FTP...). Costumam ocupar mais espaço do que os anexos sem uso.
 *
 * Esta classe só CLASSIFICA e decide o que pode ou não ser tocado. Quem move, restaura e exclui é a
 * HMC_Orphan_Quarantine.
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
	const ORIGINAL = 'original'; // Original grande de uma imagem que o WordPress reduziu (-scaled); o anexo só registra a reduzida.
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

		// O arquivo "base" existe se algum anexo o registra ou registra a versão -scaled dele: quando a
		// imagem é grande, o WordPress passa a usar a -scaled e, em muitos sites, deixa de anotar o original.
		$tem_base = function ( $nome ) use ( $known, $prefix ) {
			if ( $known( $prefix . $nome ) ) {
				return true;
			}
			return (bool) preg_match( '/^(.+)(\.[a-z0-9]+)$/', $nome, $m ) && $known( $prefix . $m[1] . '-scaled' . $m[2] );
		};

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
			if ( $tem_base( $stem ) ) {
				return $kind;
			}
		}

		if ( $stem !== $name && $tem_base( $stem ) ) {
			return $kind;
		}
		// Sem sufixo e sem registro, mas a versão -scaled dele é registrada: é o original grande.
		if ( $stem === $name && preg_match( '/^(.+)(\.[a-z0-9]+)$/', $name, $m ) && $known( $prefix . $m[1] . '-scaled' . $m[2] ) ) {
			return $in_use( $rel ) ? self::IN_USE : self::ORIGINAL;
		}
		return $in_use( $rel ) ? self::IN_USE : self::LOOSE;
	}

	/**
	 * Tipos que podem ir para a quarentena. "Em uso" nunca é candidato.
	 *
	 * @return string[]
	 */
	public static function movable_kinds() {
		return array( self::THUMB, self::SCALED, self::EDIT, self::WEBP, self::JUNK, self::EMPTY_, self::ORIGINAL, self::LOOSE );
	}

	/**
	 * Tipos em que um engano custa mais caro e que pedem confirmação reforçada: cópias que um plugin
	 * pode entregar aos visitantes, originais (o WordPress não os registra) e arquivos sem dono.
	 *
	 * @return string[]
	 */
	public static function sensitive_kinds() {
		return array( self::WEBP, self::ORIGINAL, self::LOOSE );
	}

	/**
	 * Risco de mover/excluir cada tipo: baixo (restos que o WordPress gera e não usa), médio (cópias que
	 * um plugin pode entregar, ou arquivos sem dono) ou alto (originais, que ninguém registra).
	 *
	 * @param string $kind Tipo.
	 * @return string low|medium|high
	 */
	public static function risk( $kind ) {
		if ( self::ORIGINAL === $kind ) {
			return 'high';
		}
		if ( in_array( $kind, array( self::WEBP, self::LOOSE ), true ) ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Ordem recomendada para esvaziar os órfãos: do mais seguro ao mais sensível.
	 *
	 * @return string[]
	 */
	public static function plan_order() {
		return array( self::JUNK, self::EMPTY_, self::EDIT, self::SCALED, self::THUMB, self::WEBP, self::LOOSE, self::ORIGINAL );
	}

	/**
	 * Caminho de órfão que o plugin pode tocar: relativo a uploads, seguro, na raiz ou numa pasta de
	 * data (2026/10/...), fora da quarentena e que não seja arquivo oculto ou de sistema.
	 *
	 * @param mixed $rel Caminho vindo do banco ou do painel.
	 * @return string|false Caminho normalizado ou false.
	 */
	public static function path_allowed( $rel ) {
		$rel = HMC_Util::safe_rel( $rel );
		if ( false === $rel ) {
			return false;
		}
		$parts = explode( '/', $rel );
		$name  = strtolower( end( $parts ) );
		if ( 'hmc-quarantine' === strtolower( $parts[0] ) || in_array( $name, self::IGNORED, true ) || '.' === $name[0] ) {
			return false;
		}
		// Um arquivo na raiz de uploads, ou dentro de uma pasta de data.
		if ( count( $parts ) > 1 && ! self::top_dir_in_scope( $parts[0] ) ) {
			return false;
		}
		return $rel;
	}

	/**
	 * Arquivo modificado há menos de $days dias: pode estar acabando de ser enviado (por FTP, por
	 * exemplo) e ainda não ter sido usado em lugar nenhum.
	 */
	public static function is_recent( $mtime, $days, $now ) {
		return (int) $days > 0 && ( (int) $now - (int) $mtime ) < (int) $days * 86400;
	}

	/**
	 * Exclusão definitiva só depois do prazo e nunca para arquivo que teve acessos na quarentena.
	 */
	public static function deletable( $quarantined_at, $hits, $min_days, $now ) {
		return 0 === (int) $hits && ( (int) $now - (int) $quarantined_at ) >= (int) $min_days * 86400;
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
			self::ORIGINAL => array( __( 'Originais grandes (a versão -scaled é a usada)', 'hmediacleaner' ), __( 'Fotos originais, muitas vezes de vários MB, de imagens que o WordPress reduziu. O anexo usa a versão -scaled e não registra o original, então o WordPress não o enxerga e não o apaga. Só mantenha se pretende regenerar miniaturas a partir dele.', 'hmediacleaner' ) ),
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
