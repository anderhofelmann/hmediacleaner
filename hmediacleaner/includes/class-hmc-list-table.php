<?php
/**
 * Lista dos resultados da análise, com ações de quarentena.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class HMC_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'hmc_item',
				'plural'   => 'hmc_items',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Visões da lista: chave => [rótulo, condição SQL].
	 * Itens em quarentena (ou já excluídos) só aparecem nas visões de quarentena.
	 */
	public static function views_def() {
		$st   = HMC_DB::statuses();
		$live = "q_status IN ('','restored')";
		return array(
			''           => array( __( 'Candidatos à limpeza', 'hmediacleaner' ), "status IN ('unused','attached') AND {$live}" ),
			'unused'     => array( $st['unused'], "status = 'unused' AND {$live}" ),
			'attached'   => array( $st['attached'], "status = 'attached' AND {$live}" ),
			'dup_unused' => array( __( 'Duplicatas sem uso', 'hmediacleaner' ), "dup_of IS NOT NULL AND status <> 'used' AND {$live}" ),
			'dup_used'   => array( __( 'Duplicatas em uso', 'hmediacleaner' ), "dup_of IS NOT NULL AND status = 'used' AND {$live}" ),
			'missing'    => array( $st['missing'], "(status = 'missing' OR (status = 'used' AND orig_bytes = 0)) AND {$live}" ),
			'recent'     => array( $st['recent'], "status = 'recent' AND {$live}" ),
			'used'       => array( $st['used'], "status = 'used' AND {$live}" ),
			'quarantine' => array( __( 'Em quarentena', 'hmediacleaner' ), "q_status = 'quarantined'" ),
			'q_alert'    => array( __( 'Quarentena: possível falta', 'hmediacleaner' ), "q_status = 'quarantined' AND (status = 'used' OR q_hits > 0)" ),
			'all'        => array( __( 'Todos', 'hmediacleaner' ), "q_status <> 'deleted'" ),
		);
	}

	public static function where( $view, $search = '' ) {
		global $wpdb;
		$defs  = self::views_def();
		$where = isset( $defs[ $view ] ) ? $defs[ $view ][1] : $defs[''][1];
		if ( '' !== $search ) {
			$where .= $wpdb->prepare( ' AND file LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}
		return $where;
	}

	public function current_view() {
		$v = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return array_key_exists( $v, self::views_def() ) ? $v : '';
	}

	protected function get_views() {
		global $wpdb;
		$t       = HMC_DB::items_table();
		$current = $this->current_view();
		$out     = array();
		foreach ( self::views_def() as $key => $def ) {
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$def[1]}" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $n && in_array( $key, array( 'q_alert', 'quarantine' ), true ) && $current !== $key ) {
				continue;
			}
			$url                         = add_query_arg( array( 'page' => 'hmediacleaner', 'view' => $key ), admin_url( 'upload.php' ) );
			$out[ $key ? $key : 'cand' ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				( $current === $key ? ' class="current" aria-current="page"' : '' ) . ( 'q_alert' === $key ? ' style="color:#b32d2e"' : '' ),
				esc_html( $def[0] ),
				esc_html( number_format_i18n( $n ) )
			);
		}
		return $out;
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'thumb'    => '',
			'file'     => __( 'Arquivo', 'hmediacleaner' ),
			'status'   => __( 'Situação', 'hmediacleaner' ),
			'detail'   => __( 'Detalhe', 'hmediacleaner' ),
			'bytes'    => __( 'Espaço', 'hmediacleaner' ),
			'uploaded' => __( 'Enviado em', 'hmediacleaner' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'file'     => array( 'file', false ),
			'bytes'    => array( 'bytes', true ),
			'uploaded' => array( 'uploaded', true ),
		);
	}

	protected function get_bulk_actions() {
		if ( in_array( $this->current_view(), array( 'quarantine', 'q_alert' ), true ) ) {
			return array(
				'restore' => __( 'Restaurar', 'hmediacleaner' ),
				'delete'  => __( 'Excluir definitivamente', 'hmediacleaner' ),
			);
		}
		return array( 'quarantine' => __( 'Colocar em quarentena', 'hmediacleaner' ) );
	}

	protected function column_cb( $item ) {
		$selectable = 'quarantined' === $item->q_status || ( in_array( $item->status, array( 'unused', 'attached' ), true ) && '' === $item->q_status );
		return $selectable ? '<input type="checkbox" name="ids[]" value="' . (int) $item->attachment_id . '">' : '';
	}

	public function prepare_items() {
		global $wpdb;
		$t        = HMC_DB::items_table();
		$per_page = 50;
		$paged    = $this->get_pagenum();
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$where    = self::where( $this->current_view(), $search );
		$orderby  = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'file', 'bytes', 'uploaded' ), true ) ? $_GET['orderby'] : 'bytes'; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		$order    = isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput

		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY {$orderby} {$order}, attachment_id ASC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'file' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	public function no_items() {
		esc_html_e( 'Nenhum arquivo nesta lista.', 'hmediacleaner' );
	}

	private static function action_url( $op, $id ) {
		// O nonce inclui a operação: o link de "restaurar" não serve para "excluir".
		return wp_nonce_url( add_query_arg( array( 'hmc_do' => $op, 'id' => (int) $id ) ), 'hmc_item_' . sanitize_key( $op ) . '_' . (int) $id );
	}

	protected function column_thumb( $item ) {
		if ( 0 === strpos( $item->mime, 'image/' ) && $item->orig_bytes && 'quarantined' !== $item->q_status ) {
			$src = wp_get_attachment_image_url( $item->attachment_id, 'thumbnail' );
			if ( $src ) {
				return '<img src="' . esc_url( $src ) . '" alt="" loading="lazy" width="60" height="60">';
			}
		}
		return '<span class="dashicons ' . ( 'quarantined' === $item->q_status ? 'dashicons-lock' : 'dashicons-media-default' ) . '"></span>';
	}

	protected function column_file( $item ) {
		$up      = wp_upload_dir( null, false );
		$url     = trailingslashit( $up['baseurl'] ) . $item->file;
		$edit    = 'quarantined' === $item->q_status ? '' : get_edit_post_link( $item->attachment_id );
		$out     = '<strong>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' : '' ) . esc_html( '' !== $item->file ? $item->file : '#' . $item->attachment_id ) . ( $edit ? '</a>' : '' ) . '</strong>';
		$actions = array( 'id' => 'ID ' . (int) $item->attachment_id );
		if ( 'quarantined' === $item->q_status ) {
			$actions['restore'] = '<a href="' . esc_url( self::action_url( 'restore', $item->attachment_id ) ) . '">' . esc_html__( 'Restaurar', 'hmediacleaner' ) . '</a>';
			$days               = HMC_DB::settings()['retention_days'];
			$age_ok             = $item->q_date && strtotime( $item->q_date ) <= current_time( 'timestamp' ) - $days * DAY_IN_SECONDS; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			if ( $age_ok && 'used' !== $item->status && ! (int) $item->q_hits ) {
				$actions['delete'] = '<a class="hmc-confirm" data-confirm="' . esc_attr__( 'Excluir definitivamente este arquivo? Esta ação não pode ser desfeita.', 'hmediacleaner' ) . '" href="' . esc_url( self::action_url( 'delete', $item->attachment_id ) ) . '" style="color:#b32d2e">' . esc_html__( 'Excluir definitivamente', 'hmediacleaner' ) . '</a>';
			}
		} else {
			$actions['view'] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver arquivo', 'hmediacleaner' ) . '</a>';
			if ( in_array( $item->status, array( 'unused', 'attached' ), true ) && '' === $item->q_status ) {
				$actions['quarantine'] = '<a href="' . esc_url( self::action_url( 'quarantine', $item->attachment_id ) ) . '">' . esc_html__( 'Colocar em quarentena', 'hmediacleaner' ) . '</a>';
			}
		}
		return $out . $this->row_actions( $actions, true );
	}

	protected function column_status( $item ) {
		$labels = HMC_DB::statuses();
		$out    = '';
		if ( 'quarantined' === $item->q_status ) {
			$out .= '<span class="hmc-badge hmc-quarantine">' . esc_html__( 'Em quarentena', 'hmediacleaner' ) . '</span> ';
			if ( 'used' === $item->status || (int) $item->q_hits ) {
				$out .= '<span class="hmc-badge hmc-alert">' . esc_html__( 'Possível falta', 'hmediacleaner' ) . '</span>';
			}
			return $out;
		}
		$out .= '<span class="hmc-badge hmc-' . esc_attr( $item->status ) . '">' . esc_html( isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status ) . '</span>';
		if ( $item->dup_of ) {
			$out .= ' <span class="hmc-badge hmc-dup">' . esc_html__( 'Duplicata', 'hmediacleaner' ) . '</span>';
		}
		if ( 'restored' === $item->q_status ) {
			$out .= ' <span class="hmc-badge">' . esc_html__( 'Restaurado', 'hmediacleaner' ) . '</span>';
		}
		return $out;
	}

	protected function column_detail( $item ) {
		$out = array();
		if ( 'quarantined' === $item->q_status ) {
			/* translators: %s: data */
			$out[] = sprintf( esc_html__( 'Em quarentena desde %s.', 'hmediacleaner' ), esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $item->q_date ) ) );
			if ( (int) $item->q_hits ) {
				/* translators: 1: acessos, 2: data */
				$hit = sprintf( esc_html__( '%1$d acesso(s) ao arquivo depois da quarentena; último em %2$s', 'hmediacleaner' ), (int) $item->q_hits, esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $item->q_last_hit ) ) );
				if ( '' !== $item->q_referer ) {
					$hit .= ' · ' . esc_html__( 'origem:', 'hmediacleaner' ) . ' <a href="' . esc_url( $item->q_referer ) . '" target="_blank" rel="noopener">' . esc_html( $item->q_referer ) . '</a>';
				}
				$out[] = '<strong class="hmc-warn">' . $hit . '</strong>';
			}
		}
		if ( '' !== $item->used_in ) {
			/* translators: %s: onde é usado */
			$out[] = ( 'quarantined' === $item->q_status ? '<strong class="hmc-warn">' : '' ) . sprintf( esc_html__( 'Usado em: %s', 'hmediacleaner' ), HMC_Admin::describe_source( $item->used_in ) ) . ( 'quarantined' === $item->q_status ? '</strong>' : '' );
		}
		if ( '' !== $item->reason && ! ( 'quarantined' === $item->q_status && 'used' !== $item->status ) ) {
			$out[] = esc_html( $item->reason );
		}
		if ( $item->parent_id ) {
			$link  = get_edit_post_link( $item->parent_id );
			$title = get_the_title( $item->parent_id );
			/* translators: %s: item */
			$out[] = sprintf( esc_html__( 'Vinculado a: %s', 'hmediacleaner' ), $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( '' !== $title ? $title : '#' . $item->parent_id ) . '</a>' : '#' . (int) $item->parent_id );
		}
		if ( $item->dup_of ) {
			$link = get_edit_post_link( $item->dup_of );
			/* translators: %s: ID do original */
			$out[] = sprintf( esc_html__( 'Cópia idêntica de %s', 'hmediacleaner' ), $link ? '<a href="' . esc_url( $link ) . '">#' . (int) $item->dup_of . '</a>' : '#' . (int) $item->dup_of );
		}
		return implode( '<br>', $out );
	}

	protected function column_bytes( $item ) {
		/* translators: %d: quantidade de arquivos */
		return esc_html( size_format( $item->bytes, 1 ) ) . '<br><span class="hmc-muted">' . esc_html( sprintf( _n( '%d arquivo', '%d arquivos', (int) $item->files_count, 'hmediacleaner' ), (int) $item->files_count ) ) . '</span>';
	}

	protected function column_uploaded( $item ) {
		return $item->uploaded ? esc_html( mysql2date( get_option( 'date_format' ), $item->uploaded ) ) : '';
	}
}
