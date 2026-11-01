<?php
/**
 * Página Mídia → HMediaCleaner: resumo, análise com barra de progresso, lista filtrável,
 * quarentena (individual, por seleção e em lote), exportação CSV, configurações e Sobre.
 */

defined( 'ABSPATH' ) || exit;

class HMC_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_hmc_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_hmc_bulk', array( $this, 'ajax_bulk' ) );
		add_action( 'admin_post_hmc_export', array( $this, 'export' ) );
		add_action( 'admin_post_hmc_orphans_export', array( $this, 'export_orphans' ) );
		add_action( 'admin_post_hmc_settings', array( $this, 'save_settings' ) );
		add_action( 'load-media_page_hmediacleaner', array( $this, 'handle_actions' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HMC_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		add_media_page( 'HMediaCleaner', 'HMediaCleaner', self::CAP, 'hmediacleaner', array( $this, 'page' ) );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'upload.php?page=hmediacleaner' ) ) . '">' . esc_html__( 'Abrir', 'hmediacleaner' ) . '</a>' );
		return $links;
	}

	public function assets( $hook ) {
		if ( 'media_page_hmediacleaner' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hmc-admin', HMC_URL . 'assets/admin.css', array(), HMC_VERSION );
		wp_enqueue_script( 'hmc-admin', HMC_URL . 'assets/admin.js', array( 'jquery' ), HMC_VERSION, true );
		wp_localize_script(
			'hmc-admin',
			'HMC',
			array(
				'nonce'      => wp_create_nonce( 'hmc_scan' ),
				'bulkNonce'  => wp_create_nonce( 'hmc_bulk' ),
				'confirm'    => __( 'Iniciar uma nova análise? O relatório atual será substituído. Nada no site é alterado.', 'hmediacleaner' ),
				'error'      => __( 'A operação foi interrompida por um erro de comunicação. Clique novamente para retomar de onde parou.', 'hmediacleaner' ),
				'busy'       => __( 'Outra etapa ainda está em execução; aguardando...', 'hmediacleaner' ),
				'done'       => __( 'Concluído. Carregando o relatório...', 'hmediacleaner' ),
				/* translators: 1: processados, 2: total, 3: concluídos, 4: ignorados */
				'bulkStatus' => __( 'Processados %1$s de %2$s · %3$s concluídos · %4$s ignorados', 'hmediacleaner' ),
			)
		);
	}

	/* ---------------------------------------------------------------- análise */

	public function ajax_scan() {
		check_ajax_referer( 'hmc_scan' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$scanner = new HMC_Scanner();
		if ( isset( $_POST['do'] ) && 'start' === $_POST['do'] && ! get_transient( 'hmc_bulk_lock' ) ) {
			$scanner->start();
		}
		wp_send_json_success( self::progress( $scanner->run( 8 ) ) );
	}

	private static function progress( array $s ) {
		$steps = HMC_Scanner::steps();
		$keys  = array_keys( $steps );
		$index = array_search( $s['step'], $keys, true );
		// A barra acompanha a etapa atual; sem total conhecido, fica indeterminada (null).
		$percent = null;
		if ( $s['finished'] ) {
			$percent = 100;
		} elseif ( $s['step_total'] > 0 ) {
			$percent = (int) floor( min( 1, $s['done'] / $s['step_total'] ) * 100 );
		}
		return array(
			'finished' => (bool) $s['finished'],
			'busy'     => ! empty( $s['busy'] ),
			/* translators: 1: número da etapa, 2: total de etapas */
			'step'     => sprintf( __( 'Etapa %1$d de %2$d', 'hmediacleaner' ), ( false === $index ? 0 : $index ) + 1, count( $keys ) ),
			'label'    => isset( $steps[ $s['step'] ] ) ? $steps[ $s['step'] ] : '',
			'percent'  => $percent,
			'detail'   => $s['step_total'] ? sprintf( '%s / %s (%d%%)', number_format_i18n( min( $s['done'], $s['step_total'] ) ), number_format_i18n( $s['step_total'] ), (int) $percent ) : '',
		);
	}

	/* ---------------------------------------------------------------- quarentena */

	/**
	 * Itens elegíveis para uma operação, a partir de uma visão da lista ou de IDs escolhidos.
	 */
	private static function bulk_where( $op, $view, $search ) {
		require_once HMC_DIR . 'includes/class-hmc-list-table.php';
		$where = HMC_List_Table::where( $view, $search );
		if ( 'quarantine' === $op ) {
			return $where . " AND q_status = '' AND status IN ('unused','attached')";
		}
		$where .= " AND q_status = 'quarantined'";
		if ( 'delete' === $op ) {
			$days   = HMC_DB::settings()['retention_days'];
			$where .= " AND status <> 'used' AND q_hits = 0 AND q_date <= '" . esc_sql( wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) . "'";
		}
		return $where;
	}

	/**
	 * Executa uma operação em um lote de itens. Retorna [concluídos, ignorados, mensagens, último ID].
	 */
	private static function run_op( $op, array $ids ) {
		$ok   = 0;
		$skip = 0;
		$msgs = array();
		$last = 0;
		$used = 'quarantine' === $op ? array_flip( HMC_Quarantine::live_refs( $ids ) ) : array();
		$days = HMC_DB::settings()['retention_days'];
		$warn = array();
		$elig = null;
		if ( 'quarantine' === $op ) {
			global $wpdb;
			// Só entra em quarentena o que a última análise classificou como sem uso.
			$in   = implode( ',', array_map( 'intval', $ids ) );
			$elig = array_flip( array_map( 'intval', $wpdb->get_col( 'SELECT attachment_id FROM ' . HMC_DB::items_table() . " WHERE attachment_id IN ({$in}) AND q_status = '' AND status IN ('unused','attached')" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( 'delete' === $op ) {
			global $wpdb;
			// Nunca excluir o que teve acessos na quarentena ou voltou a ser usado.
			$in   = implode( ',', array_map( 'intval', $ids ) );
			$warn = array_flip( array_map( 'intval', $wpdb->get_col( 'SELECT attachment_id FROM ' . HMC_DB::items_table() . " WHERE attachment_id IN ({$in}) AND (status = 'used' OR q_hits > 0)" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		foreach ( $ids as $id ) {
			$last = max( $last, (int) $id );
			if ( null !== $elig && ! isset( $elig[ (int) $id ] ) ) {
				$res = __( 'não está classificado como sem uso na última análise.', 'hmediacleaner' );
			} elseif ( isset( $used[ (int) $id ] ) ) {
				$res = __( 'passou a ser usada como foto de produto, galeria ou categoria; faça uma nova análise.', 'hmediacleaner' );
			} elseif ( isset( $warn[ (int) $id ] ) ) {
				$res = __( 'teve acessos na quarentena ou voltou a ser usado; restaure em vez de excluir.', 'hmediacleaner' );
			} elseif ( 'quarantine' === $op ) {
				$res = HMC_Quarantine::quarantine( $id );
			} elseif ( 'restore' === $op ) {
				$res = HMC_Quarantine::restore( $id );
			} else {
				$res = HMC_Quarantine::delete( $id, $days );
			}
			if ( true === $res ) {
				$ok++;
			} else {
				$skip++;
				if ( count( $msgs ) < 5 ) {
					$msgs[] = '#' . (int) $id . ': ' . $res;
				}
			}
		}
		return array( $ok, $skip, $msgs, $last );
	}

	private static function op_allowed( $op ) {
		if ( ! in_array( $op, array( 'quarantine', 'restore', 'delete' ), true ) ) {
			return __( 'Operação inválida.', 'hmediacleaner' );
		}
		if ( 'quarantine' === $op ) {
			return HMC_DB::scan_is_fresh();
		}
		$state = HMC_Scanner::state();
		if ( '' !== $state['step'] && ! $state['finished'] ) {
			return __( 'Há uma análise em andamento. Aguarde a conclusão.', 'hmediacleaner' );
		}
		return true;
	}

	/**
	 * Operação em lote sobre uma visão inteira, em partes de até ~8 s (chamada repetidamente pelo navegador).
	 */
	public function ajax_bulk() {
		global $wpdb;
		check_ajax_referer( 'hmc_bulk' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'hmediacleaner' ) ), 403 );
		}
		$op      = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$view    = isset( $_POST['view'] ) ? sanitize_key( $_POST['view'] ) : '';
		$search  = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$cursor  = isset( $_POST['cursor'] ) ? (int) $_POST['cursor'] : 0;
		$allowed = self::op_allowed( $op );
		if ( true !== $allowed ) {
			wp_send_json_error( array( 'message' => $allowed ) );
		}
		if ( get_transient( 'hmc_bulk_lock' ) ) {
			wp_send_json_success( array( 'busy' => true, 'cursor' => $cursor ) );
		}
		set_transient( 'hmc_bulk_lock', 1, 120 );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$t     = HMC_DB::items_table();
		$where = self::bulk_where( $op, $view, $search );
		$total = 0 === $cursor ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ) : null; // phpcs:ignore WordPress.DB.PreparedSQL
		$ok    = 0;
		$skip  = 0;
		$msgs  = array();
		$done  = false;
		$t0    = microtime( true );
		while ( microtime( true ) - $t0 < 8 ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$t} WHERE {$where} AND attachment_id > %d ORDER BY attachment_id LIMIT 50", $cursor ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $ids ) {
				$done = true;
				break;
			}
			list( $o, $sk, $m, $cursor ) = self::run_op( $op, $ids );
			$ok                         += $o;
			$skip                       += $sk;
			$msgs                        = array_slice( array_merge( $msgs, $m ), 0, 5 );
		}
		delete_transient( 'hmc_bulk_lock' );
		if ( $done ) {
			self::refresh_summary_quarantine();
		}
		wp_send_json_success(
			array(
				'done'     => $done,
				'cursor'   => $cursor,
				'total'    => $total,
				'ok'       => $ok,
				'skipped'  => $skip,
				'messages' => $msgs,
			)
		);
	}

	/**
	 * Ações por item (links) e por seleção (caixas de seleção da lista).
	 */
	public function handle_actions() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$op  = '';
		$ids = array();
		if ( isset( $_GET['hmc_do'], $_GET['id'] ) ) {
			// O nonce vale para esta operação neste anexo (um link de "restaurar" não serve para "excluir").
			$op = sanitize_key( $_GET['hmc_do'] );
			check_admin_referer( 'hmc_item_' . $op . '_' . (int) $_GET['id'] );
			$ids = array( (int) $_GET['id'] );
		} elseif ( ! empty( $_GET['ids'] ) && ( ! empty( $_GET['action'] ) || ! empty( $_GET['action2'] ) ) ) {
			check_admin_referer( 'bulk-hmc_items' );
			$op  = sanitize_key( ! empty( $_GET['action'] ) && '-1' !== $_GET['action'] ? $_GET['action'] : $_GET['action2'] );
			$ids = array_map( 'intval', (array) $_GET['ids'] );
		}
		if ( ! $op || ! $ids || '-1' === $op ) {
			return;
		}
		$allowed = self::op_allowed( $op );
		$back    = remove_query_arg( array( 'hmc_do', 'id', 'ids', 'action', 'action2', '_wpnonce', '_wp_http_referer', 'hmc_msg' ) );
		if ( true !== $allowed ) {
			set_transient( 'hmc_notice_' . get_current_user_id(), array( 'error', $allowed ), 60 );
			wp_safe_redirect( $back );
			exit;
		}
		list( $ok, $skip, $msgs ) = self::run_op( $op, $ids );
		self::refresh_summary_quarantine();
		$labels = array(
			'quarantine' => __( 'colocado(s) em quarentena', 'hmediacleaner' ),
			'restore'    => __( 'restaurado(s)', 'hmediacleaner' ),
			'delete'     => __( 'excluído(s) definitivamente', 'hmediacleaner' ),
		);
		/* translators: 1: quantidade, 2: ação, 3: ignorados */
		$text = sprintf( __( '%1$d arquivo(s) %2$s. %3$d ignorado(s).', 'hmediacleaner' ), $ok, $labels[ $op ], $skip );
		if ( $msgs ) {
			$text .= ' ' . implode( ' · ', $msgs );
		}
		set_transient( 'hmc_notice_' . get_current_user_id(), array( $skip ? 'warning' : 'success', $text ), 60 );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Atualiza só os números da quarentena no resumo, sem refazer a análise.
	 */
	private static function refresh_summary_quarantine() {
		global $wpdb;
		$sum = get_option( 'hmc_summary' );
		if ( ! $sum ) {
			return;
		}
		$t                 = HMC_DB::items_table();
		$q                 = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b, COALESCE(SUM(status = 'used' OR q_hits > 0),0) a FROM {$t} WHERE q_status = 'quarantined'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sum['quarantine'] = array( 'count' => (int) $q->n, 'bytes' => (int) $q->b, 'alert' => (int) $q->a );
		$sum['status']     = array();
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) n, SUM(bytes) b FROM {$t} WHERE q_status IN ('','restored') GROUP BY status" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$sum['status'][ $r->status ] = array( 'count' => (int) $r->n, 'bytes' => (int) $r->b );
		}
		$dup               = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM {$t} WHERE dup_of IS NOT NULL AND status <> 'used' AND q_status IN ('','restored')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sum['dup_unused'] = array( 'count' => (int) $dup->n, 'bytes' => (int) $dup->b );
		$tot               = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM {$t} WHERE q_status <> 'deleted'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sum['total']      = array( 'count' => (int) $tot->n, 'bytes' => (int) $tot->b );
		update_option( 'hmc_summary', $sum, false );
	}

	/* ---------------------------------------------------------------- configurações */

	public function save_settings() {
		check_admin_referer( 'hmc_settings' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'hmediacleaner' ) );
		}
		$int = function ( $key, $min, $max, $default ) {
			return isset( $_POST[ $key ] ) ? max( $min, min( $max, (int) $_POST[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
		};
		update_option(
			'hmc_settings',
			array(
				'protect_days'   => $int( 'protect_days', 0, 3650, 7 ),
				'scan_max_age'   => $int( 'scan_max_age', 1, 720, 24 ),
				'retention_days' => $int( 'retention_days', 0, 3650, 30 ),
				'theme_files'    => empty( $_POST['theme_files'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification
				'frontend'       => empty( $_POST['frontend'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification
					'orphans'        => empty( $_POST['orphans'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification
			),
			false
		);
		wp_safe_redirect( admin_url( 'upload.php?page=hmediacleaner&tab=settings&saved=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- página */

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'report'; // phpcs:ignore WordPress.Security.NonceVerification
		$state   = HMC_Scanner::state();
		$summary = get_option( 'hmc_summary' );
		$running = '' !== $state['step'] && ! $state['finished'];
		$base    = admin_url( 'upload.php?page=hmediacleaner' );
		$notice  = get_transient( 'hmc_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'hmc_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap hmc-wrap">
			<h1>HMediaCleaner</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>
			<p class="hmc-lead"><?php esc_html_e( 'Encontra arquivos da biblioteca de mídia sem uso e duplicados, procurando referências em todo o site: conteúdo, produtos e galerias, construtores de página, categorias, configurações, arquivos do tema e as páginas já montadas. Os arquivos sem uso podem ir para a quarentena, de onde voltam com um clique.', 'hmediacleaner' ); ?></p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base ); ?>" class="nav-tab <?php echo in_array( $tab, array( 'settings', 'about', 'orphans' ), true ) ? '' : 'nav-tab-active'; ?>"><?php esc_html_e( 'Relatório', 'hmediacleaner' ); ?></a>
				<a href="<?php echo esc_url( $base . '&tab=orphans' ); ?>" class="nav-tab <?php echo 'orphans' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Órfãos', 'hmediacleaner' ); ?></a>
				<a href="<?php echo esc_url( $base . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Configurações', 'hmediacleaner' ); ?></a>
				<a href="<?php echo esc_url( $base . '&tab=about' ); ?>" class="nav-tab <?php echo 'about' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Sobre', 'hmediacleaner' ); ?></a>
			</nav>

			<?php
			if ( in_array( $tab, array( 'settings', 'about', 'orphans' ), true ) ) {
				if ( 'settings' === $tab ) {
					$this->settings_tab();
				} elseif ( 'orphans' === $tab ) {
					$this->orphans_tab( $running );
				} else {
					$this->about_tab();
				}
				echo '</div>';
				return;
			}
			?>

			<div class="hmc-scan">
				<p>
					<button type="button" class="button button-primary" id="hmc-start" <?php disabled( $running ); ?>><?php esc_html_e( 'Iniciar nova análise', 'hmediacleaner' ); ?></button>
					<?php if ( $running ) : ?>
						<button type="button" class="button" id="hmc-continue"><?php esc_html_e( 'Continuar análise', 'hmediacleaner' ); ?></button>
					<?php endif; ?>
					<?php if ( $summary ) : ?>
						<span class="hmc-muted">
							<?php
							/* translators: 1: data, 2: duração */
							printf( esc_html__( 'Última análise: %1$s (levou %2$s).', 'hmediacleaner' ), esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $summary['finished'] ) ), esc_html( human_time_diff( 0, max( 1, $summary['duration'] ) ) ) );
							?>
						</span>
					<?php endif; ?>
				</p>
				<?php $p = self::progress( $state ); ?>
				<div id="hmc-progress" class="hmc-progress" <?php echo $running ? '' : 'hidden'; ?> data-running="<?php echo $running ? '1' : '0'; ?>">
					<p class="hmc-progress-head"><strong id="hmc-step"><?php echo esc_html( $p['step'] ); ?></strong> · <span id="hmc-label"><?php echo esc_html( $p['label'] ); ?></span></p>
					<div class="hmc-bar<?php echo null === $p['percent'] ? ' is-indeterminate' : ''; ?>"><span style="width:<?php echo null === $p['percent'] ? 100 : (int) $p['percent']; ?>%"></span></div>
					<p><span id="hmc-detail" class="hmc-muted"><?php echo esc_html( $p['detail'] ); ?></span></p>
					<p class="hmc-muted"><?php esc_html_e( 'A análise roda em pequenas etapas para não pesar no site. Mantenha esta página aberta; se fechar, é possível continuar depois.', 'hmediacleaner' ); ?></p>
				</div>
			</div>

			<?php
			if ( $summary && ! $running ) {
				$this->summary_cards( $summary );
				$this->items_table( $summary );
			} elseif ( ! $summary && ! $running ) {
				echo '<p>' . esc_html__( 'Nenhuma análise realizada ainda. Clique em "Iniciar nova análise".', 'hmediacleaner' ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	private function settings_tab() {
		$settings = HMC_DB::settings();
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configurações salvas.', 'hmediacleaner' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hmc_settings">
			<?php wp_nonce_field( 'hmc_settings' ); ?>
			<h2><?php esc_html_e( 'Análise', 'hmediacleaner' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hmc-protect"><?php esc_html_e( 'Proteger envios recentes', 'hmediacleaner' ); ?></label></th>
					<td>
						<input type="number" min="0" max="3650" id="hmc-protect" name="protect_days" value="<?php echo (int) $settings['protect_days']; ?>" class="small-text"> <?php esc_html_e( 'dias', 'hmediacleaner' ); ?>
						<p class="description"><?php esc_html_e( 'Arquivos enviados há menos tempo que isso nunca são classificados como sem uso (podem estar aguardando publicação).', 'hmediacleaner' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fontes adicionais', 'hmediacleaner' ); ?></th>
					<td>
						<label><input type="checkbox" name="theme_files" value="1" <?php checked( $settings['theme_files'] ); ?>> <?php esc_html_e( 'Ler os arquivos do tema e dos mu-plugins (imagens escritas no código do cabeçalho, rodapé e CSS)', 'hmediacleaner' ); ?></label><br>
						<label><input type="checkbox" name="frontend" value="1" <?php checked( $settings['frontend'] ); ?>> <?php esc_html_e( 'Visitar as principais páginas do site e ler o HTML final (cabeçalho, rodapé e conteúdo inserido por plugins)', 'hmediacleaner' ); ?></label><br>
						<label><input type="checkbox" name="orphans" value="1" <?php checked( $settings['orphans'] ); ?>> <?php esc_html_e( 'Procurar arquivos órfãos no disco (miniaturas antigas, versões editadas, logs e arquivos soltos). Só relatório: nada é movido nem apagado.', 'hmediacleaner' ); ?></label>
					</td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'Quarentena', 'hmediacleaner' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hmc-maxage"><?php esc_html_e( 'Validade da análise', 'hmediacleaner' ); ?></label></th>
					<td>
						<input type="number" min="1" max="720" id="hmc-maxage" name="scan_max_age" value="<?php echo (int) $settings['scan_max_age']; ?>" class="small-text"> <?php esc_html_e( 'horas', 'hmediacleaner' ); ?>
						<p class="description"><?php esc_html_e( 'Só é possível colocar arquivos em quarentena com base em uma análise feita dentro deste prazo.', 'hmediacleaner' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hmc-retention"><?php esc_html_e( 'Tempo mínimo em quarentena', 'hmediacleaner' ); ?></label></th>
					<td>
						<input type="number" min="0" max="3650" id="hmc-retention" name="retention_days" value="<?php echo (int) $settings['retention_days']; ?>" class="small-text"> <?php esc_html_e( 'dias', 'hmediacleaner' ); ?>
						<p class="description"><?php esc_html_e( 'A exclusão definitiva só fica disponível depois deste prazo, e nunca para arquivos que tiveram acessos ou voltaram a ser usados.', 'hmediacleaner' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Salvar', 'hmediacleaner' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Arquivos órfãos: somente relatório. Nada aqui move ou apaga arquivos.
	 */
	private function orphans_tab( $running ) {
		global $wpdb;
		$sum   = get_option( 'hmc_orphan_summary' );
		$kinds = HMC_Orphans::kinds();
		$base  = admin_url( 'upload.php?page=hmediacleaner&tab=orphans' );
		echo '<p class="hmc-lead">' . esc_html__( 'Arquivos que estão na pasta de uploads, mas que nenhum item da biblioteca de mídia registra: miniaturas de tamanhos antigos, versões editadas, cópias de compressão, logs e arquivos enviados por FTP. Costumam ocupar mais espaço do que os itens sem uso. Esta aba é só um relatório: nada é movido nem apagado.', 'hmediacleaner' ) . '</p>';
		if ( ! $sum || $running ) {
			echo '<p>' . esc_html( $running ? __( 'Aguarde o fim da análise.', 'hmediacleaner' ) : __( 'Faça uma análise (aba Relatório) para listar os arquivos órfãos.', 'hmediacleaner' ) ) . '</p>';
			return;
		}
		$counts = isset( $sum['kinds'] ) && is_array( $sum['kinds'] ) ? $sum['kinds'] : array();
		if ( ! HMC_DB::settings()['orphans'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'A procura de órfãos está desligada nas configurações.', 'hmediacleaner' ) . '</p></div>';
		}
		$free = 0;
		foreach ( array( HMC_Orphans::THUMB, HMC_Orphans::SCALED, HMC_Orphans::EDIT, HMC_Orphans::JUNK, HMC_Orphans::EMPTY_, HMC_Orphans::LOOSE ) as $k ) {
			$free += isset( $counts[ $k ] ) ? (int) $counts[ $k ]['bytes'] : 0;
		}
		/* translators: %s: tamanho */
		echo '<p class="hmc-free">' . esc_html( sprintf( __( 'Espaço potencialmente recuperável (sem contar as cópias WebP): %s', 'hmediacleaner' ), size_format( $free, 1 ) ) ) . '</p>';

		echo '<table class="widefat striped hmc-about-table"><thead><tr><th>' . esc_html__( 'Tipo', 'hmediacleaner' ) . '</th><th>' . esc_html__( 'Arquivos', 'hmediacleaner' ) . '</th><th>' . esc_html__( 'Espaço', 'hmediacleaner' ) . '</th><th>' . esc_html__( 'O que é', 'hmediacleaner' ) . '</th></tr></thead><tbody>';
		foreach ( $kinds as $k => $def ) {
			$n = isset( $counts[ $k ] ) ? (int) $counts[ $k ]['count'] : 0;
			if ( ! $n ) {
				continue;
			}
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( add_query_arg( 'kind', $k, $base ) ),
				esc_html( $def[0] ),
				esc_html( number_format_i18n( $n ) ),
				esc_html( size_format( (int) $counts[ $k ]['bytes'], 1 ) ),
				esc_html( $def[1] )
			);
		}
		echo '</tbody></table>';

		// Lista dos maiores arquivos (do tipo escolhido, se houver).
		$kind  = isset( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$kind  = isset( $kinds[ $kind ] ) ? $kind : '';
		$t     = HMC_DB::orphans_table();
		$where = $kind ? $wpdb->prepare( 'WHERE kind = %s', $kind ) : "WHERE kind <> 'in_use'";
		$rows  = $wpdb->get_results( "SELECT path, bytes, mtime, kind FROM {$t} {$where} ORDER BY bytes DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$csv   = wp_nonce_url( add_query_arg( array( 'action' => 'hmc_orphans_export', 'kind' => $kind ), admin_url( 'admin-post.php' ) ), 'hmc_orphans_export' );
		echo '<h2>' . esc_html( $kind ? $kinds[ $kind ][0] : __( 'Os 100 maiores', 'hmediacleaner' ) ) . '</h2>';
		echo '<p class="hmc-export"><a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Exportar a lista completa (CSV)', 'hmediacleaner' ) . '</a>';
		if ( $kind ) {
			echo ' <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Ver todos os tipos', 'hmediacleaner' ) . '</a>';
		}
		echo '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nenhum arquivo nesta lista.', 'hmediacleaner' ) . '</p>';
			return;
		}
		$up = wp_upload_dir( null, false );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Arquivo', 'hmediacleaner' ) . '</th><th>' . esc_html__( 'Tipo', 'hmediacleaner' ) . '</th><th>' . esc_html__( 'Tamanho', 'hmediacleaner' ) . '</th><th>' . esc_html__( 'Modificado em', 'hmediacleaner' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( trailingslashit( $up['baseurl'] ) . $r->path ),
				esc_html( $r->path ),
				esc_html( isset( $kinds[ $r->kind ] ) ? $kinds[ $r->kind ][0] : $r->kind ),
				esc_html( size_format( (int) $r->bytes, 1 ) ),
				esc_html( $r->mtime ? mysql2date( get_option( 'date_format' ), $r->mtime ) : '' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * CSV dos arquivos órfãos (somente leitura).
	 */
	public function export_orphans() {
		check_admin_referer( 'hmc_orphans_export' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'hmediacleaner' ) );
		}
		global $wpdb;
		$kinds = HMC_Orphans::kinds();
		$kind  = isset( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : '';
		$kind  = isset( $kinds[ $kind ] ) ? $kind : '';
		$t     = HMC_DB::orphans_table();
		$where = $kind ? $wpdb->prepare( 'kind = %s', $kind ) : '1=1';
		$up    = wp_upload_dir( null, false );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=hmediacleaner-orfaos-' . ( $kind ? $kind : 'todos' ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para o Excel reconhecer UTF-8.
		fputcsv( $out, array( 'Arquivo', 'URL', 'Tipo', 'Tamanho (bytes)', 'Modificado em' ), ';' );
		$last = '';
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT path_key, path, bytes, mtime, kind FROM {$t} WHERE {$where} AND path_key > %s ORDER BY path_key LIMIT 2000", $last ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $r ) {
				$last = $r->path_key;
				// Células que começam com = + - @ viram texto: evita injeção de fórmula na planilha.
				fputcsv( $out, HMC_Util::csv_row( array( $r->path, trailingslashit( $up['baseurl'] ) . $r->path, isset( $kinds[ $r->kind ] ) ? $kinds[ $r->kind ][0] : $r->kind, $r->bytes, $r->mtime ) ), ';' );
			}
		} while ( count( $rows ) === 2000 );
		fclose( $out );
		exit;
	}

	private function about_tab() {
		$statuses = array(
			'used'     => __( 'Há referência ao arquivo em algum lugar do site. O relatório mostra onde, com link.', 'hmediacleaner' ),
			'unused'   => __( 'Nenhuma referência foi encontrada e o arquivo não está vinculado a nenhum conteúdo existente.', 'hmediacleaner' ),
			'attached' => __( 'O arquivo está vinculado a um post ou produto, mas não é usado nele nem em outro lugar. É o caso típico de imagens reenviadas por sistemas de integração (ERP), que criam cópias a cada atualização.', 'hmediacleaner' ),
			'missing'  => __( 'O registro existe na biblioteca de mídia, mas o arquivo não está no disco. Se estiver em uso, o site mostra uma imagem quebrada.', 'hmediacleaner' ),
			'recent'   => __( 'Enviado há menos dias que o configurado. Nunca é considerado sem uso, pois pode estar aguardando publicação.', 'hmediacleaner' ),
		);
		$labels   = HMC_DB::statuses();
		$settings = HMC_DB::settings();
		?>
		<div class="hmc-about">
			<h2><?php esc_html_e( 'Como o HMediaCleaner funciona', 'hmediacleaner' ); ?></h2>
			<p><?php esc_html_e( 'Ferramentas de limpeza de mídia costumam errar porque procuram referências em poucos lugares e apagam com base nesse palpite. O HMediaCleaner segue três princípios: procurar em todo o site, considerar o arquivo em uso na dúvida e nunca apagar direto. Um falso "em uso" apenas deixa de liberar espaço; um falso "sem uso" pode quebrar o site.', 'hmediacleaner' ); ?></p>

			<h3><?php esc_html_e( '1. Busca de referências', 'hmediacleaner' ); ?></h3>
			<p><?php esc_html_e( 'Antes de classificar qualquer arquivo, o plugin lê:', 'hmediacleaner' ); ?></p>
			<ul class="hmc-list">
				<li><?php esc_html_e( 'o conteúdo de posts, páginas e produtos (HTML, blocos do editor, galerias e shortcodes);', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'a foto principal e a galeria de produtos e variações do WooCommerce;', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'campos personalizados e construtores de página, como o Elementor, inclusive cabeçalhos e rodapés montados neles;', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'categorias e termos (miniaturas e descrições);', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'configurações do site, do tema (logo, imagem de cabeçalho), de widgets e de plugins;', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'dados de usuários;', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'os arquivos do tema, do tema pai e dos mu-plugins (imagens escritas direto no código ou no CSS);', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'o HTML final das principais páginas do site (início, loja, carrinho, conta, páginas, produtos e categorias), já com cabeçalho, rodapé e o que os plugins inserem.', 'hmediacleaner' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'São reconhecidos IDs de anexo, URLs completas, URLs de miniaturas (como -300x300), versões -scaled, imagens convertidas para WebP e caminhos relativos ao diretório de uploads.', 'hmediacleaner' ); ?></p>

			<h3><?php esc_html_e( '2. Classificação', 'hmediacleaner' ); ?></h3>
			<table class="widefat striped hmc-about-table">
				<tbody>
				<?php foreach ( $statuses as $key => $text ) : ?>
					<tr>
						<td class="hmc-about-status"><span class="hmc-badge hmc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $labels[ $key ] ); ?></span></td>
						<td><?php echo esc_html( $text ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( '3. Duplicatas', 'hmediacleaner' ); ?></h3>
			<p><?php esc_html_e( 'Arquivos com exatamente o mesmo tamanho têm o conteúdo comparado por hash (MD5). Em cada grupo de arquivos idênticos, o original é o primeiro em uso ou, se nenhum estiver em uso, o mais antigo. As demais cópias são indicadas como duplicatas, com ou sem uso.', 'hmediacleaner' ); ?></p>

			<h3><?php esc_html_e( '4. Quarentena', 'hmediacleaner' ); ?></h3>
			<ul class="hmc-list">
				<li><?php esc_html_e( 'Só podem ir para a quarentena arquivos sem uso ou provavelmente sem uso, com base em uma análise recente. No momento da ação, uma nova checagem confirma que o arquivo não virou foto de produto, galeria, miniatura de categoria, logo ou ícone do site.', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'Os arquivos (original, miniaturas, versões WebP e cópias de compressão) são movidos, não apagados, para uma pasta protegida dentro de uploads. O anexo sai da biblioteca de mídia, para não ser escolhido por engano.', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'Enquanto estão em quarentena, o plugin vigia se eles fazem falta: conta os acessos aos arquivos (com data e página de origem) e, a cada nova análise, verifica se algum passou a ser usado. Nos dois casos, o painel mostra um alerta.', 'hmediacleaner' ); ?></li>
				<li><?php esc_html_e( 'A restauração devolve tudo exatamente ao lugar original, com um clique, por item, por seleção ou em lote.', 'hmediacleaner' ); ?></li>
				<li>
					<?php
					/* translators: %d: dias */
					printf( esc_html__( 'A exclusão definitiva só fica disponível para arquivos em quarentena há pelo menos %d dias (configurável), sem acessos registrados e que não voltaram a ser usados, e sempre com confirmação.', 'hmediacleaner' ), (int) $settings['retention_days'] );
					?>
				</li>
				<li><?php esc_html_e( 'Toda operação fica registrada em um log (uploads/hmc-quarantine/hmc-log.csv). Ao desinstalar o plugin, tudo o que estiver em quarentena é restaurado automaticamente.', 'hmediacleaner' ); ?></li>
			</ul>

			<h3><?php esc_html_e( '5. Desempenho', 'hmediacleaner' ); ?></h3>
			<p><?php esc_html_e( 'A análise e as operações em lote rodam em etapas curtas e retomáveis, para não pesar no site mesmo em bibliotecas com centenas de milhares de arquivos. Em servidores com WP-CLI, também podem ser executadas pelos comandos "wp hmediacleaner".', 'hmediacleaner' ); ?></p>

			<h3><?php esc_html_e( 'Limitações', 'hmediacleaner' ); ?></h3>
			<p><?php esc_html_e( 'Arquivos referenciados apenas fora do WordPress (outros sites, e-mails já enviados, páginas HTML avulsas fora do tema) não podem ser detectados. Os acessos a arquivos em quarentena só são contados quando o pedido chega ao WordPress; em servidores em que um proxy ou CDN responde direto, use a verificação feita pelas novas análises. Por isso a quarentena vem antes de qualquer exclusão.', 'hmediacleaner' ); ?></p>

			<div class="hmc-author">
				<p><strong>HMediaCleaner</strong> · <?php /* translators: %s: versão */ printf( esc_html__( 'versão %s', 'hmediacleaner' ), esc_html( HMC_VERSION ) ); ?></p>
				<p><?php esc_html_e( 'Desenvolvido por', 'hmediacleaner' ); ?> <strong>Anderson Höfelmann</strong></p>
				<p class="hmc-muted"><?php esc_html_e( 'Licença GPLv2 ou posterior.', 'hmediacleaner' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function summary_cards( array $sum ) {
		$st   = $sum['status'];
		$get  = function ( $k ) use ( $st ) {
			return isset( $st[ $k ] ) ? $st[ $k ] : array( 'count' => 0, 'bytes' => 0 );
		};
		$card = function ( $title, $count, $bytes, $class, $hint ) {
			printf(
				'<div class="hmc-card %s"><div class="hmc-card-title">%s</div><div class="hmc-card-num">%s</div><div class="hmc-card-sub">%s</div><div class="hmc-card-hint">%s</div></div>',
				esc_attr( $class ),
				esc_html( $title ),
				esc_html( number_format_i18n( $count ) ),
				esc_html( size_format( $bytes, 1 ) ),
				esc_html( $hint )
			);
		};
		$q    = isset( $sum['quarantine'] ) ? $sum['quarantine'] : array( 'count' => 0, 'bytes' => 0, 'alert' => 0 );
		$free = $get( 'unused' )['bytes'] + $get( 'attached' )['bytes'];

		if ( $q['alert'] ) {
			$url = admin_url( 'upload.php?page=hmediacleaner&view=q_alert' );
			/* translators: %s: quantidade */
			echo '<div class="notice notice-error inline hmc-alert"><p><strong>' . esc_html( sprintf( _n( '%s arquivo em quarentena parece fazer falta.', '%s arquivos em quarentena parecem fazer falta.', $q['alert'], 'hmediacleaner' ), number_format_i18n( $q['alert'] ) ) ) . '</strong> ' . esc_html__( 'Houve acessos a eles ou passaram a ser usados no site.', 'hmediacleaner' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver e restaurar', 'hmediacleaner' ) . '</a></p></div>';
		}
		if ( ! empty( $sum['front_errors'] ) ) {
			/* translators: %d: quantidade */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( '%d página(s) do site não puderam ser visitadas durante a análise. As demais fontes foram lidas normalmente.', 'hmediacleaner' ), (int) $sum['front_errors'] ) ) . '</p></div>';
		}

		echo '<div class="hmc-cards">';
		$card( __( 'Total na biblioteca', 'hmediacleaner' ), $sum['total']['count'], $sum['total']['bytes'], 'is-total', '' );
		$card( __( 'Em uso', 'hmediacleaner' ), $get( 'used' )['count'], $get( 'used' )['bytes'], 'is-used', __( 'Referenciadas em algum lugar do site.', 'hmediacleaner' ) );
		$card( __( 'Sem uso', 'hmediacleaner' ), $get( 'unused' )['count'], $get( 'unused' )['bytes'], 'is-unused', __( 'Sem referência e sem item vinculado.', 'hmediacleaner' ) );
		$card( __( 'Provavelmente sem uso', 'hmediacleaner' ), $get( 'attached' )['count'], $get( 'attached' )['bytes'], 'is-attached', __( 'Vinculadas a um item, mas não usadas nele.', 'hmediacleaner' ) );
		$card( __( 'Duplicatas sem uso', 'hmediacleaner' ), $sum['dup_unused']['count'], $sum['dup_unused']['bytes'], 'is-dup', __( 'Cópias idênticas de outro arquivo.', 'hmediacleaner' ) );
		$card( __( 'Duplicatas em uso', 'hmediacleaner' ), $sum['dup_used']['count'], $sum['dup_used']['bytes'], 'is-dupused', __( 'Cópias idênticas em uso; podem ser unificadas.', 'hmediacleaner' ) );
		$card( __( 'Em quarentena', 'hmediacleaner' ), $q['count'], $q['bytes'], 'is-quarantine', __( 'Fora da biblioteca, prontas para restaurar ou excluir.', 'hmediacleaner' ) );
		echo '</div>';
		/* translators: %s: tamanho */
		echo '<p class="hmc-free">' . esc_html( sprintf( __( 'Espaço potencialmente recuperável: %s', 'hmediacleaner' ), size_format( $free, 1 ) ) ) . '</p>';
	}

	private function items_table( array $summary ) {
		global $wpdb;
		require_once HMC_DIR . 'includes/class-hmc-list-table.php';
		$table = new HMC_List_Table();
		$table->prepare_items();
		$view   = $table->current_view();
		$s      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$csv    = wp_nonce_url( add_query_arg( array( 'action' => 'hmc_export', 'view' => $view, 's' => $s ), admin_url( 'admin-post.php' ) ), 'hmc_export' );
		$t      = HMC_DB::items_table();
		$fresh  = HMC_DB::scan_is_fresh();
		$days   = HMC_DB::settings()['retention_days'];
		$bulk   = array();
		$is_q   = in_array( $view, array( 'quarantine', 'q_alert' ), true );
		if ( ! $is_q && 'used' !== $view && 'recent' !== $view && 'missing' !== $view ) {
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE " . self::bulk_where( 'quarantine', $view, $s ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $n ) {
				/* translators: %s: quantidade */
				$bulk[] = array( 'quarantine', sprintf( __( 'Colocar os %s arquivos desta lista em quarentena', 'hmediacleaner' ), number_format_i18n( $n ) ), $n, sprintf( __( 'Mover %s arquivos para a quarentena? Eles saem da biblioteca de mídia e podem ser restaurados a qualquer momento.', 'hmediacleaner' ), number_format_i18n( $n ) ), 'button-primary' );
			}
		}
		if ( $is_q ) {
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE " . self::bulk_where( 'restore', $view, $s ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $n ) {
				/* translators: %s: quantidade */
				$bulk[] = array( 'restore', sprintf( __( 'Restaurar os %s arquivos desta lista', 'hmediacleaner' ), number_format_i18n( $n ) ), $n, sprintf( __( 'Restaurar %s arquivos para a biblioteca de mídia?', 'hmediacleaner' ), number_format_i18n( $n ) ), '' );
			}
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE " . self::bulk_where( 'delete', $view, $s ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $n && 'q_alert' !== $view ) {
				/* translators: 1: quantidade, 2: dias */
				$bulk[] = array( 'delete', sprintf( __( 'Excluir definitivamente %1$s arquivos (há mais de %2$d dias em quarentena, sem acessos)', 'hmediacleaner' ), number_format_i18n( $n ), $days ), $n, sprintf( __( 'EXCLUIR DEFINITIVAMENTE %s arquivos? Esta ação não pode ser desfeita. Recomenda-se ter um backup do site.', 'hmediacleaner' ), number_format_i18n( $n ) ), 'button-link-delete' );
			}
		}

		if ( $bulk ) {
			echo '<div class="hmc-bulk">';
			if ( ! $is_q && true !== $fresh ) {
				echo '<p class="hmc-muted">' . esc_html( $fresh ) . '</p>';
			}
			foreach ( $bulk as $b ) {
				printf(
					'<button type="button" class="button %s hmc-bulk-btn" data-op="%s" data-view="%s" data-s="%s" data-confirm="%s" %s>%s</button> ',
					esc_attr( $b[4] ),
					esc_attr( $b[0] ),
					esc_attr( $view ),
					esc_attr( $s ),
					esc_attr( $b[3] ),
					disabled( 'quarantine' === $b[0] && true !== $fresh, true, false ),
					esc_html( $b[1] )
				);
			}
			echo '<div id="hmc-bulk-progress" class="hmc-progress" hidden><div class="hmc-bar"><span style="width:0%"></span></div><p id="hmc-bulk-status" class="hmc-muted"></p><ul id="hmc-bulk-msgs" class="hmc-muted"></ul></div>';
			echo '</div>';
		}

		echo '<form method="get" id="hmc-items-form">';
		echo '<input type="hidden" name="page" value="hmediacleaner">';
		if ( $view ) {
			echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
		}
		$table->views();
		echo '<p class="hmc-export"><a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Exportar esta lista (CSV)', 'hmediacleaner' ) . '</a></p>';
		$table->search_box( __( 'Buscar arquivo', 'hmediacleaner' ), 'hmc-search' );
		$table->display();
		echo '</form>';
	}

	/* ---------------------------------------------------------------- CSV */

	public function export() {
		check_admin_referer( 'hmc_export' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'hmediacleaner' ) );
		}
		global $wpdb;
		require_once HMC_DIR . 'includes/class-hmc-list-table.php';
		$view  = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
		$s     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$where = HMC_List_Table::where( $view, $s );
		$t     = HMC_DB::items_table();
		$up    = wp_upload_dir( null, false );
		$label = HMC_DB::statuses();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=hmediacleaner-' . ( $view ? $view : 'candidatos' ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para o Excel reconhecer UTF-8.
		fputcsv( $out, array( 'ID', 'Arquivo', 'URL', 'Status', 'Motivo', 'Usado em', 'Vinculado a', 'Tamanho (bytes)', 'Arquivos', 'Enviado em', 'Duplicata de', 'Quarentena', 'Em quarentena desde', 'Acessos na quarentena', 'Último acesso', 'Origem do último acesso' ), ';' );
		$last = 0;
		do {
			$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE {$where} AND attachment_id > {$last} ORDER BY attachment_id LIMIT 2000" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $r ) {
				$last = (int) $r->attachment_id;
				// Células que começam com = + - @ viram texto: evita injeção de fórmula na planilha.
				fputcsv(
					$out,
					HMC_Util::csv_row(
						array(
							$r->attachment_id,
							$r->file,
							trailingslashit( $up['baseurl'] ) . $r->file,
							isset( $label[ $r->status ] ) ? $label[ $r->status ] : $r->status,
							$r->reason,
							$r->used_in,
							$r->parent_id ? $r->parent_id : '',
							$r->bytes,
							$r->files_count,
							$r->uploaded,
							$r->dup_of ? $r->dup_of : '',
							$r->q_status,
							$r->q_date,
							$r->q_hits,
							$r->q_last_hit,
							$r->q_referer,
						)
					),
					';'
				);
			}
		} while ( count( $rows ) === 2000 );
		fclose( $out );
		exit;
	}

	/**
	 * Texto e link legíveis para a origem de uma referência ("post:123", "meta:_thumbnail_id:123",
	 * "url:https://...", "file:themes/tema/header.php"...).
	 */
	public static function describe_source( $src ) {
		if ( 0 === strpos( $src, 'url:' ) ) {
			$url = substr( $src, 4 );
			/* translators: %s: link da página */
			return sprintf( esc_html__( 'Página %s', 'hmediacleaner' ), '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $url, PHP_URL_PATH ) ? wp_parse_url( $url, PHP_URL_PATH ) : '/' ) . '</a>' );
		}
		if ( 0 === strpos( $src, 'file:' ) ) {
			/* translators: %s: arquivo */
			return esc_html( sprintf( __( 'Arquivo %s', 'hmediacleaner' ), substr( $src, 5 ) ) );
		}
		$parts = explode( ':', $src );
		$type  = $parts[0];
		$id    = (int) end( $parts );
		switch ( $type ) {
			case 'post':
			case 'meta':
				$title = get_the_title( $id );
				$link  = get_edit_post_link( $id );
				$what  = ( 'meta' === $type && count( $parts ) >= 3 ) ? sprintf( '%s · ', implode( ':', array_slice( $parts, 1, -1 ) ) ) : '';
				$name  = $what . ( '' !== $title ? $title : '#' . $id ) . ' (' . get_post_type( $id ) . ')';
				return $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
			case 'term':
				$term = get_term( $id );
				$name = ( $term && ! is_wp_error( $term ) ) ? $term->name . ' (' . $term->taxonomy . ')' : '#' . $id;
				$link = ( $term && ! is_wp_error( $term ) ) ? get_edit_term_link( $term ) : '';
				return $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
			case 'option':
				/* translators: %s: nome da opção */
				return esc_html( sprintf( __( 'Configuração "%s"', 'hmediacleaner' ), substr( $src, 7 ) ) );
			case 'user':
				$user = get_userdata( $id );
				/* translators: %s: usuário */
				return esc_html( sprintf( __( 'Usuário %s', 'hmediacleaner' ), $user ? $user->display_name : '#' . $id ) );
		}
		return esc_html( $src );
	}
}
