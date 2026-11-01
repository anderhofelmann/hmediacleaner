<?php
/**
 * Comandos WP-CLI. Úteis em bibliotecas grandes: a análise roda no servidor, sem depender do navegador.
 */

defined( 'ABSPATH' ) || exit;

class HMC_CLI {

	/**
	 * Analisa a biblioteca de mídia (somente leitura).
	 *
	 * ## OPTIONS
	 *
	 * [--continue]
	 * : Retoma uma análise interrompida em vez de começar outra.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hmediacleaner scan
	 */
	public function scan( $args, $assoc ) {
		$scanner = new HMC_Scanner();
		$state   = HMC_Scanner::state();
		if ( empty( $assoc['continue'] ) || '' === $state['step'] || $state['finished'] ) {
			$scanner->start();
		}
		$steps = HMC_Scanner::steps();
		$last  = '';
		do {
			$s = $scanner->run( 20 );
			if ( ! empty( $s['busy'] ) ) {
				WP_CLI::error( 'Outra análise está em execução (pelo painel?). Aguarde ou use --continue mais tarde.' );
			}
			$line = isset( $steps[ $s['step'] ] ) ? $steps[ $s['step'] ] : $s['step'];
			if ( $s['step_total'] ) {
				$line .= sprintf( ' (%s/%s)', number_format( min( $s['done'], $s['step_total'] ), 0, ',', '.' ), number_format( $s['step_total'], 0, ',', '.' ) );
			}
			if ( $line !== $last ) {
				WP_CLI::log( $line );
				$last = $line;
			}
		} while ( ! $s['finished'] );
		WP_CLI::success( 'Análise concluída.' );
		$this->summary( array(), array() );
	}

	/**
	 * Mostra o resumo da última análise.
	 */
	public function summary( $args, $assoc ) {
		$sum = get_option( 'hmc_summary' );
		if ( ! $sum ) {
			WP_CLI::error( 'Nenhuma análise concluída. Rode: wp hmediacleaner scan' );
		}
		$rows = array();
		foreach ( HMC_DB::statuses() as $key => $label ) {
			$v      = isset( $sum['status'][ $key ] ) ? $sum['status'][ $key ] : array( 'count' => 0, 'bytes' => 0 );
			$rows[] = array( 'Situação' => $label, 'Arquivos' => $v['count'], 'Espaço' => size_format( $v['bytes'], 1 ) );
		}
		$rows[] = array( 'Situação' => 'Duplicatas sem uso', 'Arquivos' => $sum['dup_unused']['count'], 'Espaço' => size_format( $sum['dup_unused']['bytes'], 1 ) );
		$rows[] = array( 'Situação' => 'Duplicatas em uso', 'Arquivos' => $sum['dup_used']['count'], 'Espaço' => size_format( $sum['dup_used']['bytes'], 1 ) );
		$rows[] = array( 'Situação' => 'TOTAL', 'Arquivos' => $sum['total']['count'], 'Espaço' => size_format( $sum['total']['bytes'], 1 ) );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'Situação', 'Arquivos', 'Espaço' ) );
		WP_CLI::log( sprintf( 'Referências encontradas no site: %s. Duração: %s.', number_format( $sum['refs'], 0, ',', '.' ), human_time_diff( 0, max( 1, $sum['duration'] ) ) ) );
	}

	/**
	 * Lista os arquivos de uma visão do relatório.
	 *
	 * ## OPTIONS
	 *
	 * [--view=<view>]
	 * : Candidatos (padrão), unused, attached, dup_unused, dup_used, missing, recent, used ou all.
	 *
	 * [--format=<format>]
	 * : table, csv ou json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		global $wpdb;
		require_once HMC_DIR . 'includes/class-hmc-list-table.php';
		$where = HMC_List_Table::where( isset( $assoc['view'] ) ? $assoc['view'] : '' );
		$rows  = $wpdb->get_results( 'SELECT attachment_id, file, status, bytes, parent_id, dup_of, used_in, reason FROM ' . HMC_DB::items_table() . " WHERE {$where} ORDER BY attachment_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		WP_CLI\Utils\format_items( $assoc['format'], $rows, array( 'attachment_id', 'file', 'status', 'bytes', 'parent_id', 'dup_of', 'used_in', 'reason' ) );
	}

	/**
	 * Resume (ou lista) os arquivos órfãos encontrados na última análise. Somente leitura.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kind>]
	 * : Lista os arquivos de um tipo: thumb, scaled, edit, webp, junk, empty, original, loose ou in_use.
	 *
	 * [--limit=<n>]
	 * : Quantos arquivos listar, dos maiores para os menores.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv ou json.
	 * ---
	 * default: table
	 * ---
	 */
	public function orphans( $args, $assoc ) {
		global $wpdb;
		$kinds = HMC_Orphans::kinds();
		$sum   = get_option( 'hmc_orphan_summary' );
		if ( ! $sum ) {
			WP_CLI::error( 'Nenhuma análise concluída. Rode: wp hmediacleaner scan' );
		}
		if ( empty( $assoc['kind'] ) ) {
			$rows = array();
			foreach ( $kinds as $k => $def ) {
				$v = isset( $sum['kinds'][ $k ] ) ? $sum['kinds'][ $k ] : array( 'count' => 0, 'bytes' => 0 );
				if ( $v['count'] ) {
					$rows[] = array( 'Tipo' => $k, 'O que é' => $def[0], 'Arquivos' => $v['count'], 'Espaço' => size_format( $v['bytes'], 1 ) );
				}
			}
			if ( ! $rows ) {
				WP_CLI::success( 'Nenhum arquivo órfão encontrado.' );
				return;
			}
			WP_CLI\Utils\format_items( $assoc['format'], $rows, array( 'Tipo', 'O que é', 'Arquivos', 'Espaço' ) );
			WP_CLI::log( 'Somente relatório: nada é movido nem apagado. Detalhes de um tipo: wp hmediacleaner orphans --kind=<tipo>' );
			return;
		}
		$kind = sanitize_key( $assoc['kind'] );
		if ( ! isset( $kinds[ $kind ] ) ) {
			WP_CLI::error( 'Tipo inválido. Use: ' . implode( ', ', array_keys( $kinds ) ) );
		}
		$limit = max( 1, min( 100000, (int) $assoc['limit'] ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT path, bytes, mtime FROM ' . HMC_DB::orphans_table() . ' WHERE kind = %s ORDER BY bytes DESC LIMIT %d', $kind, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		WP_CLI\Utils\format_items( $assoc['format'], $rows, array( 'path', 'bytes', 'mtime' ) );
	}

	/**
	 * Mostra onde um anexo é usado, segundo a última análise.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID do anexo.
	 */
	public function why( $args ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . HMC_DB::items_table() . ' WHERE attachment_id = %d', (int) $args[0] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			WP_CLI::error( 'Anexo não encontrado no relatório.' );
		}
		foreach ( $row as $k => $v ) {
			WP_CLI::log( str_pad( $k, 14 ) . ' ' . $v );
		}
	}

	/**
	 * Coloca em quarentena os arquivos sem uso de uma visão do relatório.
	 *
	 * ## OPTIONS
	 *
	 * [--view=<view>]
	 * : Candidatos (padrão), unused, attached ou dup_unused.
	 *
	 * [--yes]
	 * : Não pedir confirmação.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hmediacleaner quarantine --view=dup_unused
	 */
	public function quarantine( $args, $assoc ) {
		$fresh = HMC_DB::scan_is_fresh();
		if ( true !== $fresh ) {
			WP_CLI::error( $fresh );
		}
		$this->bulk( 'quarantine', isset( $assoc['view'] ) ? $assoc['view'] : '', $assoc, 'Colocar em quarentena %d arquivos?' );
	}

	/**
	 * Restaura arquivos da quarentena.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : IDs dos anexos a restaurar.
	 *
	 * [--all]
	 * : Restaurar tudo o que está em quarentena.
	 *
	 * [--yes]
	 * : Não pedir confirmação.
	 */
	public function restore( $args, $assoc ) {
		if ( $args ) {
			foreach ( $args as $id ) {
				$res = HMC_Quarantine::restore( (int) $id );
				true === $res ? WP_CLI::log( "#{$id} restaurado." ) : WP_CLI::warning( "#{$id}: {$res}" );
			}
			return;
		}
		if ( empty( $assoc['all'] ) ) {
			WP_CLI::error( 'Informe os IDs ou use --all.' );
		}
		$this->bulk( 'restore', 'quarantine', $assoc, 'Restaurar %d arquivos?' );
	}

	/**
	 * Exclui definitivamente os arquivos em quarentena há mais tempo que o configurado, sem acessos
	 * registrados e que não voltaram a ser usados.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Não pedir confirmação.
	 */
	public function purge( $args, $assoc ) {
		$this->bulk( 'delete', 'quarantine', $assoc, 'EXCLUIR DEFINITIVAMENTE %d arquivos? Não pode ser desfeito.' );
	}

	private function bulk( $op, $view, $assoc, $question ) {
		global $wpdb;
		require_once HMC_DIR . 'includes/class-hmc-list-table.php';
		$where = HMC_List_Table::where( $view );
		if ( 'quarantine' === $op ) {
			$where .= " AND q_status = '' AND status IN ('unused','attached')";
		} else {
			$where .= " AND q_status = 'quarantined'";
		}
		if ( 'delete' === $op ) {
			$days   = HMC_DB::settings()['retention_days'];
			$where .= " AND status <> 'used' AND q_hits = 0 AND q_date <= '" . esc_sql( wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) . "'";
		}
		$t     = HMC_DB::items_table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $total ) {
			WP_CLI::success( 'Nada a fazer.' );
			return;
		}
		WP_CLI::confirm( sprintf( $question, $total ), $assoc );
		$days     = HMC_DB::settings()['retention_days'];
		$progress = WP_CLI\Utils\make_progress_bar( 'Processando', $total );
		$cursor   = 0;
		$ok       = 0;
		$skip     = 0;
		while ( true ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$t} WHERE {$where} AND attachment_id > %d ORDER BY attachment_id LIMIT 100", $cursor ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $ids ) {
				break;
			}
			$used = 'quarantine' === $op ? array_flip( HMC_Quarantine::live_refs( $ids ) ) : array();
			foreach ( $ids as $id ) {
				$cursor = (int) $id;
				if ( isset( $used[ $cursor ] ) ) {
					$res = 'passou a ser usado; faça nova análise';
				} elseif ( 'quarantine' === $op ) {
					$res = HMC_Quarantine::quarantine( $cursor );
				} elseif ( 'restore' === $op ) {
					$res = HMC_Quarantine::restore( $cursor );
				} else {
					$res = HMC_Quarantine::delete( $cursor, $days );
				}
				if ( true === $res ) {
					$ok++;
				} else {
					$skip++;
					WP_CLI::debug( "#{$cursor}: {$res}", 'hmediacleaner' );
				}
				$progress->tick();
			}
		}
		$progress->finish();
		WP_CLI::success( sprintf( '%d concluído(s), %d ignorado(s). Rode "wp hmediacleaner scan" para atualizar o resumo.', $ok, $skip ) );
	}
}
