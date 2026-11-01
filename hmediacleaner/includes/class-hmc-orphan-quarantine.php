<?php
/**
 * Quarentena de arquivos órfãos (que estão em uploads mas nenhum anexo registra).
 *
 * Os arquivos são MOVIDOS (nunca apagados) para uploads/hmc-quarantine/orphans/<mesmo caminho>, e podem
 * voltar exatamente ao lugar de origem. Cada etapa se protege:
 *
 *  - só entra o que a última análise classificou como órfão elegível (nunca "em uso") e a análise
 *    precisa ser recente (checado por quem chama, com HMC_DB::scan_is_fresh());
 *  - no momento da ação, o arquivo precisa existir, ser comum (não link simbólico), estar de fato
 *    dentro de uploads e ter o mesmo tamanho e a mesma data da análise;
 *  - arquivos modificados há poucos dias são protegidos (podem ter acabado de chegar por FTP);
 *  - se um anexo passou a registrar o caminho, o arquivo não é movido;
 *  - a restauração nunca sobrescreve um arquivo que já existe no destino;
 *  - a exclusão definitiva só vale depois do prazo da quarentena e nunca para arquivos que tiveram
 *    acessos (pedidos 404 ao endereço original) enquanto estavam em quarentena.
 */

defined( 'ABSPATH' ) || exit;

class HMC_Orphan_Quarantine {

	const QUARANTINED = 'quarantined';
	const RESTORED    = 'restored';
	const DELETED     = 'deleted';

	/** Pasta, dentro da quarentena, onde ficam os órfãos. */
	public static function dir() {
		return HMC_Quarantine::dir() . '/orphans';
	}

	/* ---------------------------------------------------------------- mover */

	/**
	 * Move um órfão para a quarentena.
	 *
	 * @param string $rel Caminho relativo a uploads, como está no relatório.
	 * @return true|string true ou o motivo de não ter movido.
	 */
	public static function quarantine( $rel ) {
		global $wpdb;
		$rel = HMC_Orphans::path_allowed( $rel );
		if ( false === $rel ) {
			return __( 'Caminho não permitido.', 'hmediacleaner' );
		}
		$key = HMC_Orphans::key( $rel );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT path, bytes, mtime, kind FROM ' . HMC_DB::orphans_table() . ' WHERE path_key = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row || $row->path !== $rel ) {
			return __( 'Não consta como órfão na última análise.', 'hmediacleaner' );
		}
		if ( ! in_array( $row->kind, HMC_Orphans::movable_kinds(), true ) ) {
			return __( 'Este tipo de arquivo não pode ser movido (está em uso).', 'hmediacleaner' );
		}

		$base = HMC_Quarantine::basedir();
		$abs  = $base . $rel;
		clearstatcache( true, $abs );
		if ( ! HMC_Util::is_plain_file( $abs ) || ! HMC_Util::inside( $abs, $base ) ) {
			return __( 'O arquivo não existe mais ou não é um arquivo comum dentro de uploads.', 'hmediacleaner' );
		}
		$size  = HMC_Util::size( $abs );
		$mtime = (int) @filemtime( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $size !== (int) $row->bytes || ( $row->mtime && abs( $mtime - (int) strtotime( $row->mtime . ' UTC' ) ) > 2 ) ) {
			return __( 'O arquivo mudou desde a última análise; faça uma nova análise.', 'hmediacleaner' );
		}
		$protect = HMC_DB::settings()['protect_days'];
		if ( HMC_Orphans::is_recent( $mtime, $protect, time() ) ) {
			/* translators: %d: dias */
			return sprintf( __( 'Modificado há menos de %d dias; protegido por precaução.', 'hmediacleaner' ), $protect );
		}
		// Algum anexo passou a registrar este arquivo depois da análise?
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $rel ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return __( 'Um item da biblioteca de mídia passou a registrar este arquivo; faça uma nova análise.', 'hmediacleaner' );
		}
		if ( ! HMC_Quarantine::ensure_dir() ) {
			return __( 'Não foi possível criar a pasta de quarentena (verifique as permissões de uploads).', 'hmediacleaner' );
		}

		$dst = self::dir() . '/' . $rel;
		wp_mkdir_p( dirname( $dst ) );
		// O destino precisa ficar, de fato, dentro da pasta de órfãos da quarentena, e não pode existir.
		if ( ! HMC_Util::dir_inside( dirname( $dst ), self::dir() ) || file_exists( $dst ) || ! @rename( $abs, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return __( 'Falha ao mover o arquivo; nada foi alterado.', 'hmediacleaner' );
		}
		// Confere o resultado: se o tamanho não bate, desfaz.
		if ( HMC_Util::size( $dst ) !== $size ) {
			@rename( $dst, $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return __( 'A conferência do arquivo movido falhou; ele foi devolvido ao lugar.', 'hmediacleaner' );
		}

		$wpdb->replace(
			HMC_DB::oq_table(),
			array(
				'path_key'   => $key,
				'fkey'       => HMC_Refs::file_key( $rel ),
				'path'       => substr( $rel, 0, 400 ),
				'kind'       => $row->kind,
				'bytes'      => $size,
				'mtime'      => $row->mtime,
				'q_status'   => self::QUARANTINED,
				'q_date'     => current_time( 'mysql' ),
				'q_user'     => get_current_user_id(),
				'q_hits'     => 0,
				'q_last_hit' => null,
				'q_referer'  => '',
			)
		);
		$wpdb->delete( HMC_DB::orphans_table(), array( 'path_key' => $key ) );
		HMC_Quarantine::log( 'quarentena-orfao', 0, array( $rel ) );
		return true;
	}

	/* ---------------------------------------------------------------- restaurar */

	/**
	 * Devolve um órfão da quarentena ao lugar de origem.
	 *
	 * @return true|string
	 */
	public static function restore( $rel ) {
		global $wpdb;
		$rel = HMC_Orphans::path_allowed( $rel );
		if ( false === $rel ) {
			return __( 'Os dados da quarentena deste arquivo são inválidos; a restauração foi cancelada por segurança.', 'hmediacleaner' );
		}
		$key = HMC_Orphans::key( $rel );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT path, kind, bytes, mtime FROM ' . HMC_DB::oq_table() . ' WHERE path_key = %s AND q_status = %s', $key, self::QUARANTINED ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row || $row->path !== $rel ) {
			return __( 'Este arquivo não está em quarentena.', 'hmediacleaner' );
		}
		$base = HMC_Quarantine::basedir();
		$src  = self::dir() . '/' . $rel;
		$dst  = $base . $rel;
		if ( ! HMC_Util::is_plain_file( $src ) || ! HMC_Util::inside( $src, self::dir() ) ) {
			return __( 'O arquivo não está mais na pasta de quarentena.', 'hmediacleaner' );
		}
		if ( file_exists( $dst ) ) {
			return __( 'Já existe um arquivo no lugar original; a restauração foi cancelada para não sobrescrevê-lo.', 'hmediacleaner' );
		}
		wp_mkdir_p( dirname( $dst ) );
		if ( ! HMC_Util::dir_inside( dirname( $dst ), $base ) || ! @rename( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return __( 'Falha ao restaurar o arquivo; nada foi alterado.', 'hmediacleaner' );
		}
		self::rmdir_empty_parents( dirname( $src ) );
		$wpdb->update( HMC_DB::oq_table(), array( 'q_status' => self::RESTORED ), array( 'path_key' => $key ) );
		// Continua órfão: volta à lista do relatório (a próxima análise confirma).
		$wpdb->replace( HMC_DB::orphans_table(), array( 'path_key' => $key, 'path' => $rel, 'bytes' => (int) $row->bytes, 'mtime' => $row->mtime, 'kind' => $row->kind ) );
		HMC_Quarantine::log( 'restaurado-orfao', 0, array( $rel ) );
		return true;
	}

	/* ---------------------------------------------------------------- excluir */

	/**
	 * Exclusão definitiva de um órfão em quarentena.
	 *
	 * @return true|string
	 */
	public static function delete( $rel, $min_days ) {
		global $wpdb;
		$rel = HMC_Orphans::path_allowed( $rel );
		if ( false === $rel ) {
			return __( 'Caminho não permitido.', 'hmediacleaner' );
		}
		$key = HMC_Orphans::key( $rel );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT path, q_date, q_hits FROM ' . HMC_DB::oq_table() . ' WHERE path_key = %s AND q_status = %s', $key, self::QUARANTINED ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row || $row->path !== $rel ) {
			return __( 'Só é possível excluir definitivamente arquivos que estão em quarentena.', 'hmediacleaner' );
		}
		if ( (int) $row->q_hits > 0 ) {
			return __( 'Teve acessos na quarentena; restaure em vez de excluir.', 'hmediacleaner' );
		}
		$since = (int) get_gmt_from_date( $row->q_date, 'U' );
		if ( ! HMC_Orphans::deletable( $since, (int) $row->q_hits, (int) $min_days, time() ) ) {
			/* translators: %d: dias */
			return sprintf( __( 'Está em quarentena há menos de %d dias.', 'hmediacleaner' ), (int) $min_days );
		}
		$src = self::dir() . '/' . $rel;
		if ( HMC_Util::is_plain_file( $src ) && HMC_Util::inside( $src, self::dir() ) ) {
			if ( ! @unlink( $src ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return __( 'Não foi possível excluir o arquivo.', 'hmediacleaner' );
			}
			self::rmdir_empty_parents( dirname( $src ) );
		}
		$wpdb->update( HMC_DB::oq_table(), array( 'q_status' => self::DELETED ), array( 'path_key' => $key ) );
		HMC_Quarantine::log( 'excluido-orfao', 0, array( $rel ) );
		return true;
	}

	/* ---------------------------------------------------------------- acessos */

	/**
	 * Conta um pedido 404 ao endereço original de um órfão em quarentena: sinal de que ele faz falta.
	 * Qualquer visitante pode pedir esse endereço, então a escrita é limitada (uma por arquivo a cada 5 min).
	 */
	public static function track_404( $url_rel ) {
		global $wpdb;
		$t   = HMC_DB::oq_table();
		$key = (string) $wpdb->get_var( $wpdb->prepare( "SELECT path_key FROM {$t} WHERE fkey = %s AND q_status = %s LIMIT 1", HMC_Refs::file_key( $url_rel ), self::QUARANTINED ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '' === $key || get_transient( 'hmc_ohit_' . $key ) ) {
			return;
		}
		set_transient( 'hmc_ohit_' . $key, 1, 5 * MINUTE_IN_SECONDS );
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 255 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET q_hits = q_hits + 1, q_last_hit = %s, q_referer = %s WHERE path_key = %s", current_time( 'mysql' ), $referer, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ---------------------------------------------------------------- lotes e resumo */

	/**
	 * Condição SQL (sobre a tabela da operação) dos itens que uma operação em lote alcança.
	 *
	 * @param string $op   quarantine, restore ou delete.
	 * @param string $kind Tipo (vazio = todos).
	 * @return array array( tabela, condição )
	 */
	public static function selection( $op, $kind = '' ) {
		global $wpdb;
		$kinds = HMC_Orphans::kinds();
		$kind  = isset( $kinds[ $kind ] ) ? $kind : '';
		if ( 'quarantine' === $op ) {
			$in    = "'" . implode( "','", array_map( 'esc_sql', HMC_Orphans::movable_kinds() ) ) . "'";
			$where = "kind IN ({$in})";
			$table = HMC_DB::orphans_table();
		} else {
			$where = $wpdb->prepare( 'q_status = %s', self::QUARANTINED );
			$table = HMC_DB::oq_table();
			if ( 'delete' === $op ) {
				$days   = HMC_DB::settings()['retention_days'];
				$where .= $wpdb->prepare( ' AND q_hits = 0 AND q_date <= %s', wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
			}
		}
		if ( '' !== $kind ) {
			$where .= $wpdb->prepare( ' AND kind = %s', $kind );
		}
		return array( $table, $where );
	}

	/**
	 * Executa uma operação em um lote de caminhos. Retorna [concluídos, ignorados, mensagens].
	 */
	public static function run_batch( $op, array $paths ) {
		$ok   = 0;
		$skip = 0;
		$msgs = array();
		$days = HMC_DB::settings()['retention_days'];
		foreach ( $paths as $rel ) {
			if ( 'quarantine' === $op ) {
				$res = self::quarantine( $rel );
			} elseif ( 'restore' === $op ) {
				$res = self::restore( $rel );
			} else {
				$res = self::delete( $rel, $days );
			}
			if ( true === $res ) {
				$ok++;
			} else {
				$skip++;
				if ( count( $msgs ) < 5 ) {
					$msgs[] = $rel . ': ' . $res;
				}
			}
		}
		return array( $ok, $skip, $msgs );
	}

	/**
	 * Atualiza o resumo de órfãos mostrado no painel (a tabela do relatório perde os arquivos movidos).
	 */
	public static function refresh_summary() {
		global $wpdb;
		$orph = array();
		foreach ( $wpdb->get_results( 'SELECT kind, COUNT(*) n, COALESCE(SUM(bytes),0) b FROM ' . HMC_DB::orphans_table() . ' GROUP BY kind' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$orph[ $r->kind ] = array( 'count' => (int) $r->n, 'bytes' => (int) $r->b );
		}
		$old = get_option( 'hmc_orphan_summary' );
		update_option( 'hmc_orphan_summary', array( 'finished' => is_array( $old ) && isset( $old['finished'] ) ? $old['finished'] : time(), 'kinds' => $orph ), false );
	}

	/**
	 * Resumo do que está em quarentena, por tipo.
	 *
	 * @return array tipo => array( count, bytes, hits, deletable )
	 */
	public static function summary() {
		global $wpdb;
		$days = HMC_DB::settings()['retention_days'];
		$cut  = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$out  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT kind, COUNT(*) n, COALESCE(SUM(bytes),0) b, COALESCE(SUM(q_hits > 0),0) h, COALESCE(SUM(q_hits = 0 AND q_date <= %s),0) d FROM ' . HMC_DB::oq_table() . ' WHERE q_status = %s GROUP BY kind', $cut, self::QUARANTINED ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ $r->kind ] = array( 'count' => (int) $r->n, 'bytes' => (int) $r->b, 'hits' => (int) $r->h, 'deletable' => (int) $r->d );
		}
		return $out;
	}

	/**
	 * Restaura todos os órfãos em quarentena (usado na desinstalação). Retorna [restaurados, falhas].
	 */
	public static function restore_all() {
		global $wpdb;
		$ok   = 0;
		$fail = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT path FROM ' . HMC_DB::oq_table() . ' WHERE q_status = %s', self::QUARANTINED ) ) as $rel ) { // phpcs:ignore WordPress.DB.PreparedSQL
			true === self::restore( $rel ) ? $ok++ : $fail++;
		}
		return array( $ok, $fail );
	}

	/**
	 * Remove pastas vazias, de baixo para cima, sem sair da pasta de órfãos da quarentena.
	 */
	private static function rmdir_empty_parents( $dir ) {
		$root = realpath( self::dir() );
		$cur  = realpath( $dir );
		while ( $root && $cur && $cur !== $root && 0 === strpos( $cur, $root . DIRECTORY_SEPARATOR ) ) {
			if ( ! @rmdir( $cur ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break; // Não está vazia.
			}
			$cur = dirname( $cur );
		}
	}
}
