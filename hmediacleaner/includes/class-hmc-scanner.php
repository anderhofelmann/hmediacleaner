<?php
/**
 * Análise em etapas curtas e retomáveis (painel via AJAX ou WP-CLI), para não pesar no site.
 *
 * 1. Coleta referências a mídia em posts, campos personalizados, termos, opções e usuários.
 * 2. Classifica cada anexo da biblioteca de mídia (em uso, sem uso, provavelmente sem uso...).
 * 3. Encontra arquivos idênticos (mesmo conteúdo) entre os anexos.
 *
 * Somente leitura em relação ao site: grava apenas nas tabelas e opções do próprio plugin.
 */

defined( 'ABSPATH' ) || exit;

class HMC_Scanner {

	/** Campos sem relação com mídia, pulados para ganhar tempo. */
	const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_price', '_regular_price', '_sale_price', '_stock', '_stock_status', 'total_sales', '_sku', '_weight', '_length', '_width', '_height', '_manage_stock', '_backorders', '_sold_individually', '_virtual', '_downloadable', '_tax_status', '_tax_class', '_wc_average_rating', '_wc_review_count', '_wc_rating_count', '_product_version' );

	/**
	 * Campos que nunca guardam referência a mídia, ignorados já na consulta ao banco.
	 * Outros plugins ou o tema podem acrescentar os seus pelo filtro hmc_skip_meta_keys.
	 */
	private function skip_meta_keys() {
		$keys = apply_filters( 'hmc_skip_meta_keys', self::SKIP_META );
		return array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ) ) ) );
	}

	/**
	 * Tipos de conteúdo sem mídia (pedidos, cupons...), ignorados na leitura dos campos personalizados.
	 * Em lojas com muitos pedidos isso evita ler a maior parte da tabela de campos.
	 */
	private function skip_post_types() {
		$types = apply_filters( 'hmc_skip_post_types', array( 'attachment', 'revision', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon', 'scheduled-action', 'shop_subscription' ) );
		return array_values( array_unique( array_filter( array_map( 'strval', (array) $types ) ) ) );
	}

	/** @var HMC_Refs */
	private $refs;

	/** @var array Referências aguardando gravação: [kind, ref, src]. */
	private $buf = array();

	public function __construct() {
		$this->refs = new HMC_Refs();
	}

	public static function steps() {
		return array(
			'reset'       => __( 'Preparando', 'hmediacleaner' ),
			'posts'       => __( 'Lendo conteúdo de posts, páginas e produtos', 'hmediacleaner' ),
			'postmeta'    => __( 'Lendo campos personalizados (galerias, construtores de página...)', 'hmediacleaner' ),
			'terms'       => __( 'Lendo categorias e termos', 'hmediacleaner' ),
			'options'     => __( 'Lendo configurações do site, tema e widgets', 'hmediacleaner' ),
			'usermeta'    => __( 'Lendo dados de usuários', 'hmediacleaner' ),
			'theme_files' => __( 'Lendo arquivos do tema (cabeçalho, rodapé, CSS)', 'hmediacleaner' ),
			'frontend'    => __( 'Visitando as principais páginas do site', 'hmediacleaner' ),
			'classify'    => __( 'Classificando os arquivos da biblioteca de mídia', 'hmediacleaner' ),
			'orphans'     => __( 'Procurando arquivos órfãos no disco', 'hmediacleaner' ),
			'dup_prepare' => __( 'Procurando possíveis duplicatas', 'hmediacleaner' ),
			'dup_quick'   => __( 'Comparando o início dos arquivos', 'hmediacleaner' ),
			'dup_filter'  => __( 'Selecionando possíveis duplicatas', 'hmediacleaner' ),
			'dup_hash'    => __( 'Comparando o conteúdo dos arquivos', 'hmediacleaner' ),
			'finish'      => __( 'Gerando o resumo', 'hmediacleaner' ),
		);
	}

	public static function state() {
		return wp_parse_args(
			get_option( 'hmc_state', array() ),
			array(
				'step'       => '',
				'cursor'     => 0,
				'done'       => 0,
				'step_total' => 0,
				'started'    => 0,
				'finished'   => 0,
			)
		);
	}

	private static function save_state( array $s ) {
		update_option( 'hmc_state', $s, false );
	}

	public function start() {
		$s = array(
			'step'       => 'reset',
			'cursor'     => 0,
			'done'       => 0,
			'step_total' => 0,
			'started'    => time(),
			'finished'   => 0,
		);
		self::save_state( $s );
		delete_transient( 'hmc_lock' );
		return $s;
	}

	/**
	 * Executa etapas até esgotar o tempo. Retorna o estado (com 'busy' se outra execução estiver em curso).
	 */
	public function run( $budget = 8 ) {
		$s = self::state();
		if ( '' === $s['step'] || $s['finished'] ) {
			return $s;
		}
		if ( get_transient( 'hmc_lock' ) || get_transient( 'hmc_bulk_lock' ) ) {
			$s['busy'] = true;
			return $s;
		}
		set_transient( 'hmc_lock', 1, 120 );

		$steps = array_keys( self::steps() );
		$t0    = microtime( true );
		while ( microtime( true ) - $t0 < $budget ) {
			$more = $this->{'step_' . $s['step']}( $s );
			if ( $more ) {
				continue;
			}
			$this->flush();
			$i = array_search( $s['step'], $steps, true );
			if ( false === $i || ! isset( $steps[ $i + 1 ] ) ) {
				$s['finished'] = time();
				break;
			}
			$s['step']       = $steps[ $i + 1 ];
			$s['cursor']     = 0;
			$s['done']       = 0;
			$s['step_total'] = 0;
		}
		$this->flush();
		self::save_state( $s );
		delete_transient( 'hmc_lock' );
		return $s;
	}

	/* ---------------------------------------------------------------- referências */

	private function add( array $found, $src ) {
		list( $ids, $files ) = $found;
		$src                 = substr( $src, 0, 191 );
		foreach ( $ids as $id ) {
			$this->buf[] = array( HMC_DB::KIND_ID, (string) $id, $src );
		}
		foreach ( $files as $f ) {
			$this->buf[] = array( HMC_DB::KIND_FILE, md5( $f ), $src );
		}
		if ( count( $this->buf ) >= 500 ) {
			$this->flush();
		}
	}

	private function flush() {
		global $wpdb;
		if ( ! $this->buf ) {
			return;
		}
		$table = HMC_DB::refs_table();
		foreach ( array_chunk( $this->buf, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $r ) {
				$values[] = $wpdb->prepare( '(%d,%s,%s)', $r[0], $r[1], $r[2] );
			}
			// INSERT IGNORE mantém a primeira origem encontrada para cada referência.
			$wpdb->query( "INSERT IGNORE INTO {$table} (kind,ref,src) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$this->buf = array();
	}

	private function step_reset( array &$s ) {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . HMC_DB::refs_table() );  // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( 'TRUNCATE TABLE ' . HMC_DB::items_table() ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( 'TRUNCATE TABLE ' . HMC_DB::orphans_table() ); // phpcs:ignore WordPress.DB.PreparedSQL
		delete_option( 'hmc_orphan_queue' );
		delete_option( 'hmc_orphan_summary' );
		delete_option( 'hmc_summary' );
		delete_option( 'hmc_front_errors' );
		return false;
	}

	private function step_posts( array &$s ) {
		global $wpdb;
		if ( ! $s['cursor'] ) {
			$s['step_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision')" );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
				WHERE ID > %d AND post_type NOT IN ('attachment','revision') ORDER BY ID LIMIT 200",
				$s['cursor']
			)
		);
		if ( ! $rows ) {
			return false;
		}
		foreach ( $rows as $r ) {
			$this->add( $this->refs->extract( $r->post_content . "\n" . $r->post_excerpt, '', true ), 'post:' . $r->ID );
			$s['cursor'] = (int) $r->ID;
		}
		$s['done'] += count( $rows );
		return true;
	}

	/**
	 * Campos personalizados dos conteúdos. O cursor percorre os IDs dos CONTEÚDOS (posts, produtos,
	 * variações, páginas...), e os campos de cada lote são buscados pelo índice de post_id. Assim os
	 * campos de pedidos, cupons e outros tipos sem mídia nunca são lidos, e a consulta custa o mesmo
	 * em qualquer ponto da tabela (varrer a tabela de campos por meta_id, ordenando, ficava cada vez
	 * mais lento em lojas com milhões de linhas).
	 */
	private function step_postmeta( array &$s ) {
		global $wpdb;
		// Os campos dos próprios anexos (_wp_attached_file etc.) ficam de fora: não são uso.
		$types = $this->skip_post_types();
		$keys  = $this->skip_meta_keys();
		$tph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$kph   = $keys ? implode( ',', array_fill( 0, count( $keys ), '%s' ) ) : "''";
		if ( ! $s['cursor'] ) {
			$s['step_total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ({$tph})", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ({$tph}) ORDER BY ID LIMIT 100", array_merge( array( $s['cursor'] ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		if ( ! $ids ) {
			return false;
		}
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key NOT IN ({$kph}) AND meta_value <> ''", $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders
		foreach ( $rows as $r ) {
			$broad = (bool) preg_match( '/elementor|builder|layout|blocks|content|_data$/i', $r->meta_key );
			$this->add( $this->refs->extract( $r->meta_value, $r->meta_key, $broad ), 'meta:' . $r->meta_key . ':' . $r->post_id );
		}
		$s['cursor'] = (int) end( $ids );
		$s['done']  += count( $ids );
		return true;
	}

	private function step_terms( array &$s ) {
		global $wpdb;
		// Fase 1: termmeta (miniatura de categoria de produto etc.). Fase 2: descrições dos termos.
		if ( empty( $s['phase'] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id LIMIT 1000", $s['cursor'] ) );
			if ( $rows ) {
				foreach ( $rows as $r ) {
					$s['cursor'] = (int) $r->meta_id;
					$this->add( $this->refs->extract( $r->meta_value, $r->meta_key, false ), 'term:' . $r->meta_key . ':' . $r->term_id );
				}
				$s['done'] += count( $rows );
				return true;
			}
			$s['phase']  = 'desc';
			$s['cursor'] = 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, description FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d AND description <> '' ORDER BY term_taxonomy_id LIMIT 500", $s['cursor'] ) );
		if ( ! $rows ) {
			unset( $s['phase'] );
			return false;
		}
		foreach ( $rows as $r ) {
			$s['cursor'] = (int) $r->term_taxonomy_id;
			$this->add( $this->refs->extract( $r->description, '', true ), 'term:description:' . $r->term_id );
		}
		$s['done'] += count( $rows );
		return true;
	}

	private function step_options( array &$s ) {
		global $wpdb;
		if ( ! $s['cursor'] ) {
			$s['step_total'] = (int) $wpdb->get_var( "SELECT MAX(option_id) FROM {$wpdb->options}" );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options}
				WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
				ORDER BY option_id LIMIT 300",
				$s['cursor'],
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%',
				$wpdb->esc_like( 'hmc_' ) . '%'
			)
		);
		if ( ! $rows ) {
			return false;
		}
		foreach ( $rows as $r ) {
			$s['cursor'] = (int) $r->option_id;
			$broad       = (bool) preg_match( '/widget|theme_mods|elementor|customiz|header|footer|builder/i', $r->option_name );
			$this->add( $this->refs->extract( $r->option_value, $r->option_name, $broad ), 'option:' . $r->option_name );
		}
		$s['done'] = $s['cursor'];
		return true;
	}

	private function step_usermeta( array &$s ) {
		global $wpdb;
		if ( ! $s['cursor'] ) {
			$s['step_total'] = (int) $wpdb->get_var( "SELECT MAX(umeta_id) FROM {$wpdb->usermeta}" );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta}
				WHERE umeta_id > %d ORDER BY umeta_id LIMIT 2000",
				$s['cursor']
			)
		);
		if ( ! $rows ) {
			return false;
		}
		foreach ( $rows as $r ) {
			$s['cursor'] = (int) $r->umeta_id;
			$v           = (string) $r->meta_value;
			if ( false === stripos( $v, '.' ) && ! preg_match( '#' . HMC_Refs::IMG_KEY . '#i', $r->meta_key ) ) {
				continue;
			}
			$this->add( $this->refs->extract( $v, $r->meta_key, false ), 'user:' . $r->meta_key . ':' . $r->user_id );
		}
		$s['done'] = $s['cursor'];
		return true;
	}

	/**
	 * Arquivos do tema ativo, do tema pai e dos mu-plugins: imagens escritas direto no código
	 * (cabeçalho, rodapé, CSS) não aparecem no banco de dados.
	 */
	private function step_theme_files( array &$s ) {
		if ( ! HMC_DB::settings()['theme_files'] ) {
			return false;
		}
		if ( ! $s['cursor'] ) {
			$list = array();
			$dirs = array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory(), defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' ), 'is_dir' ) );
			foreach ( $dirs as $dir ) {
				$it = new RecursiveIteratorIterator(
					new RecursiveCallbackFilterIterator(
						new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
						function ( $f ) {
							return ! ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', 'vendor', '.git', 'languages' ), true ) );
						}
					)
				);
				foreach ( $it as $f ) {
					if ( $f->isFile() && $f->getSize() <= 2 * MB_IN_BYTES && preg_match( '/\.(php|css|scss|js|html?|json|twig|svg|txt)$/i', $f->getFilename() ) ) {
						$list[] = $f->getPathname();
					}
				}
			}
			update_option( 'hmc_theme_files', $list, false );
			$s['step_total'] = count( $list );
			$s['cursor']     = -1; // Lista montada; o progresso fica em 'done'.
			return true;
		}
		$list  = get_option( 'hmc_theme_files', array() );
		$slice = array_slice( $list, $s['done'], 100 );
		if ( ! $slice ) {
			delete_option( 'hmc_theme_files' );
			return false;
		}
		foreach ( $slice as $path ) {
			$text = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( false !== $text ) {
				$this->add( $this->refs->extract( $text, '', false ), 'file:' . str_replace( wp_normalize_path( WP_CONTENT_DIR ) . '/', '', wp_normalize_path( $path ) ) );
			}
		}
		$s['done'] += count( $slice );
		return true;
	}

	/**
	 * Visita as principais páginas e lê o HTML final, já com cabeçalho, rodapé, widgets e o que
	 * plugins inserem na hora de montar a página.
	 */
	private function step_frontend( array &$s ) {
		if ( ! HMC_DB::settings()['frontend'] ) {
			return false;
		}
		if ( ! $s['cursor'] ) {
			$s['urls']        = $this->frontend_urls();
			$s['step_total']  = count( $s['urls'] );
			$s['cursor']      = -1;
			$s['front_error'] = 0;
			return true;
		}
		if ( $s['done'] >= count( $s['urls'] ) ) {
			update_option( 'hmc_front_errors', (int) $s['front_error'], false );
			unset( $s['urls'], $s['front_error'] );
			return false;
		}
		$url = $s['urls'][ $s['done'] ];
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => false,
				'user-agent'  => 'HMediaCleaner/' . HMC_VERSION . ' (+analise de midia)',
			)
		);
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$this->add( $this->refs->extract( wp_remote_retrieve_body( $res ), '', false ), 'url:' . $url );
		} else {
			$s['front_error']++;
		}
		$s['done']++;
		return true;
	}

	private function frontend_urls() {
		$urls = array( home_url( '/' ) );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'shop', 'cart', 'myaccount', 'checkout' ) as $page ) {
				$urls[] = wc_get_page_permalink( $page );
			}
		}
		$picks = array(
			array( 'post_type' => 'page', 'posts_per_page' => 15 ),
			array( 'post_type' => 'post', 'posts_per_page' => 3 ),
			array( 'post_type' => 'product', 'posts_per_page' => 3 ),
		);
		foreach ( $picks as $q ) {
			if ( ! post_type_exists( $q['post_type'] ) ) {
				continue;
			}
			foreach ( get_posts( $q + array( 'post_status' => 'publish', 'fields' => 'ids', 'orderby' => 'modified' ) ) as $pid ) {
				$urls[] = get_permalink( $pid );
			}
		}
		foreach ( array( 'product_cat', 'category' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_terms( array( 'taxonomy' => $tax, 'number' => 2, 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => true ) );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$link = get_term_link( $term );
						if ( ! is_wp_error( $link ) ) {
							$urls[] = $link;
						}
					}
				}
			}
		}
		$urls[] = home_url( '/?s=a' );
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/* ---------------------------------------------------------------- classificação */

	private function step_classify( array &$s ) {
		global $wpdb;
		if ( ! $s['cursor'] ) {
			$s['step_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_date, post_mime_type FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND ID > %d ORDER BY ID LIMIT 300",
				$s['cursor']
			)
		);
		if ( ! $rows ) {
			return false;
		}

		$ids     = wp_list_pluck( $rows, 'ID' );
		$in_ids  = implode( ',', array_map( 'intval', $ids ) );
		$meta    = array();
		$results = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in_ids}) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','" . HMC_Quarantine::META . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $results as $m ) {
			$meta[ $m->post_id ][ $m->meta_key ] = $m->meta_value;
		}

		$parents    = array();
		$parent_ids = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'post_parent' ) ) );
		if ( $parent_ids ) {
			$results = $wpdb->get_results( 'SELECT ID, post_type, post_status FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_unique( $parent_ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $results as $p ) {
				$parents[ $p->ID ] = $p;
			}
		}

		// Chaves de arquivo de cada anexo (o arquivo e, se houver, a versão original antes do -scaled).
		$keys = array();
		foreach ( $rows as $r ) {
			$file = isset( $meta[ $r->ID ]['_wp_attached_file'] ) ? $meta[ $r->ID ]['_wp_attached_file'] : '';
			$md   = isset( $meta[ $r->ID ]['_wp_attachment_metadata'] ) ? HMC_Util::unser( $meta[ $r->ID ]['_wp_attachment_metadata'] ) : array();
			$k    = array();
			if ( '' !== $file ) {
				$k[] = HMC_Refs::file_key( $file );
				if ( is_array( $md ) && ! empty( $md['original_image'] ) ) {
					$dir = dirname( $file );
					$k[] = HMC_Refs::file_key( ( '.' === $dir ? '' : $dir . '/' ) . $md['original_image'] );
				}
			}
			$keys[ $r->ID ] = array_unique( $k );
		}

		$used_id   = $this->lookup( HMC_DB::KIND_ID, array_map( 'strval', $ids ) );
		$all_keys  = $keys ? array_merge( ...array_values( $keys ) ) : array();
		$used_file = $this->lookup( HMC_DB::KIND_FILE, $all_keys );

		$settings = HMC_DB::settings();
		$recent   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 0, (int) $settings['protect_days'] ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$basedir  = HMC_Quarantine::basedir();
		$qdir     = HMC_Quarantine::dir();

		$values = array();
		foreach ( $rows as $r ) {
			$id   = (int) $r->ID;
			$file = isset( $meta[ $id ]['_wp_attached_file'] ) ? $meta[ $id ]['_wp_attached_file'] : '';
			$md   = isset( $meta[ $id ]['_wp_attachment_metadata'] ) ? HMC_Util::unser( $meta[ $id ]['_wp_attachment_metadata'] ) : array();
			$q    = isset( $meta[ $id ][ HMC_Quarantine::META ] ) ? HMC_Util::unser( $meta[ $id ][ HMC_Quarantine::META ] ) : null;
			$inq  = is_array( $q );
			// Arquivos em quarentena são medidos na pasta da quarentena.
			list( $bytes, $orig_bytes, $count ) = $this->disk_usage( $inq ? $qdir . '/' . $id . '/' : $basedir, $file, is_array( $md ) ? $md : array() );
			if ( ! $inq && '' !== $file ) {
				// Caminhos que este anexo registra: a procura de órfãos os usa para saber o que é "de alguém".
				foreach ( $this->registered_paths( $file, is_array( $md ) ? $md : array() ) as $rel ) {
					$this->buf[] = array( HMC_DB::KIND_KNOWN, HMC_Orphans::key( $rel ), (string) $id );
				}
				if ( count( $this->buf ) >= 500 ) {
					$this->flush();
				}
			}

			$used_in = '';
			if ( isset( $used_id[ (string) $id ] ) ) {
				$used_in = $used_id[ (string) $id ];
			} else {
				foreach ( $keys[ $id ] as $k ) {
					if ( isset( $used_file[ $k ] ) ) {
						$used_in = $used_file[ $k ];
						break;
					}
				}
			}

			$parent = (int) $r->post_parent;
			if ( '' !== $used_in ) {
				$status = 'used';
				if ( $inq ) {
					$reason = __( 'ATENÇÃO: está em quarentena, mas passou a ser referenciado no site. Restaure.', 'hmediacleaner' );
				} else {
					$reason = $orig_bytes ? '' : __( 'Em uso, mas o arquivo não existe no disco (imagem quebrada no site).', 'hmediacleaner' );
				}
			} elseif ( '' === $file || ! $orig_bytes ) {
				$status = 'missing';
				$reason = __( 'Registro sem arquivo no disco.', 'hmediacleaner' );
			} elseif ( ! $inq && $r->post_date > $recent ) {
				$status = 'recent';
				/* translators: %d: dias */
				$reason = sprintf( __( 'Enviada há menos de %d dias.', 'hmediacleaner' ), (int) $settings['protect_days'] );
			} elseif ( $parent && isset( $parents[ $parent ] ) && 'trash' !== $parents[ $parent ]->post_status ) {
				$status = 'attached';
				/* translators: 1: tipo de conteúdo, 2: ID */
				$reason = sprintf( __( 'Anexada a %1$s #%2$d, mas não é usada nele nem em outro lugar.', 'hmediacleaner' ), $parents[ $parent ]->post_type, $parent );
			} else {
				$status = 'unused';
				if ( $parent && isset( $parents[ $parent ] ) ) {
					/* translators: %d: ID */
					$reason = sprintf( __( 'Nenhuma referência; anexada a um item na lixeira (#%d).', 'hmediacleaner' ), $parent );
				} elseif ( $parent ) {
					/* translators: %d: ID */
					$reason = sprintf( __( 'Nenhuma referência; o item ao qual estava anexada (#%d) não existe mais.', 'hmediacleaner' ), $parent );
				} else {
					$reason = __( 'Nenhuma referência encontrada no site.', 'hmediacleaner' );
				}
			}

			$tz       = wp_timezone();
			$q_date   = $inq ? $wpdb->prepare( '%s', wp_date( 'Y-m-d H:i:s', (int) $q['date'], $tz ) ) : 'NULL';
			$q_last   = $inq && ! empty( $q['last_hit'] ) ? $wpdb->prepare( '%s', wp_date( 'Y-m-d H:i:s', (int) $q['last_hit'], $tz ) ) : 'NULL';
			$values[] = $wpdb->prepare(
				'(%d,%s,%s,%s,%s,%s,%s,%d,%s,%d,%d,%d,%s,',
				$id,
				substr( $file, 0, 255 ),
				'' !== $file ? HMC_Refs::file_key( $file ) : '',
				substr( (string) $r->post_mime_type, 0, 100 ),
				$status,
				substr( $reason, 0, 255 ),
				substr( $used_in, 0, 191 ),
				$parent,
				$r->post_date,
				$bytes,
				$orig_bytes,
				$count,
				$inq ? 'quarantined' : ''
			) . $q_date . ',' . $wpdb->prepare( '%d,', $inq ? (int) $q['hits'] : 0 ) . $q_last . ',' . $wpdb->prepare( '%s)', $inq ? substr( (string) $q['referer'], 0, 255 ) : '' );
			$s['cursor'] = $id;
		}

		$table = HMC_DB::items_table();
		$wpdb->query( "REPLACE INTO {$table} (attachment_id,file,fkey,mime,status,reason,used_in,parent_id,uploaded,bytes,orig_bytes,files_count,q_status,q_date,q_hits,q_last_hit,q_referer) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$s['done'] += count( $rows );
		return true;
	}

	/**
	 * @return array ref => src das referências existentes.
	 */
	private function lookup( $kind, array $refs ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $refs ) ), 500 ) as $chunk ) {
			$in      = implode( ',', array_map( array( $wpdb, 'prepare' ), array_fill( 0, count( $chunk ), '%s' ), $chunk ) );
			$results = $wpdb->get_results( $wpdb->prepare( 'SELECT ref, src FROM ' . HMC_DB::refs_table() . " WHERE kind = %d AND ref IN ({$in})", $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $results as $r ) {
				$out[ $r->ref ] = $r->src;
			}
		}
		return $out;
	}

	/**
	 * Caminhos (relativos a uploads) que um anexo registra: o arquivo, as miniaturas, o original antes do
	 * -scaled e a cópia de segurança de compressão do WP-Optimize. Cada nome vem do banco e passa por
	 * validação: o que tiver "../" ou barras é descartado.
	 *
	 * @return string[]
	 */
	private function registered_paths( $file, array $md ) {
		$file = HMC_Util::safe_rel( $file );
		if ( false === $file ) {
			return array();
		}
		$dir    = dirname( $file );
		$prefix = ( '.' === $dir ) ? '' : $dir . '/';
		$names  = array( basename( $file ) );
		if ( ! empty( $md['sizes'] ) && is_array( $md['sizes'] ) ) {
			foreach ( $md['sizes'] as $size ) {
				$n = is_array( $size ) && isset( $size['file'] ) ? HMC_Util::safe_name( $size['file'] ) : false;
				if ( false !== $n ) {
					$names[] = $n;
				}
			}
		}
		$n = isset( $md['original_image'] ) ? HMC_Util::safe_name( $md['original_image'] ) : false;
		if ( false !== $n ) {
			$names[] = $n;
		}
		$info    = pathinfo( basename( $file ) );
		$names[] = $info['filename'] . '-updraft-pre-smush-original.' . ( isset( $info['extension'] ) ? $info['extension'] : '' );
		$out     = array();
		foreach ( array_unique( $names ) as $name ) {
			$out[] = $prefix . $name;
		}
		return $out;
	}

	/**
	 * Espaço ocupado no disco pelo anexo: arquivo, miniaturas, original antes do -scaled
	 * e cópia de segurança de compressão do WP-Optimize, se existirem.
	 *
	 * Uma única consulta ao sistema de arquivos por caminho (filesize devolve false se não existe).
	 *
	 * @return array{0:int,1:int,2:int} bytes totais, bytes do arquivo principal, quantidade de arquivos.
	 */
	private function disk_usage( $basedir, $file, array $md ) {
		if ( '' === $file ) {
			return array( 0, 0, 0 );
		}
		$paths = $this->registered_paths( $file, $md );
		if ( ! $paths ) {
			return array( 0, 0, 0 );
		}
		$orig  = 0;
		$bytes = 0;
		$count = 0;
		foreach ( $paths as $i => $rel ) {
			$size = @filesize( $basedir . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $size ) {
				continue;
			}
			$bytes += (int) $size;
			$count++;
			if ( 0 === $i ) {
				$orig = (int) $size;
			}
		}
		return array( $bytes, $orig, $count );
	}

	/* ---------------------------------------------------------------- órfãos */

	/**
	 * Arquivos em uploads que nenhum anexo registra. Percorre uma pasta por vez (retomável) e só
	 * trata das pastas de data (2026/10/) e dos arquivos soltos na raiz de uploads. Somente leitura.
	 */
	private function step_orphans( array &$s ) {
		if ( ! HMC_DB::settings()['orphans'] ) {
			return false;
		}
		$basedir = HMC_Quarantine::basedir();
		if ( ! $s['cursor'] ) {
			$queue = array( '' ); // Raiz de uploads: só os arquivos soltos nela.
			$list  = @scandir( $basedir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( is_array( $list ) ? $list : array() as $e ) {
				if ( HMC_Orphans::top_dir_in_scope( $e ) && is_dir( $basedir . $e ) && ! is_link( $basedir . $e ) ) {
					$queue[] = $e;
				}
			}
			update_option( 'hmc_orphan_queue', $queue, false );
			$s['step_total'] = count( $queue );
			$s['cursor']     = -1;
			return true;
		}
		$queue = get_option( 'hmc_orphan_queue', array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			delete_option( 'hmc_orphan_queue' );
			return false;
		}
		$dir = (string) array_shift( $queue );
		$this->scan_dir_for_orphans( $basedir, $dir, $queue );
		update_option( 'hmc_orphan_queue', $queue, false );
		$s['done']++;
		$s['step_total'] = max( (int) $s['step_total'], $s['done'] + count( $queue ) );
		return true;
	}

	/**
	 * Lista os arquivos de UMA pasta, descobre quais nenhum anexo registra e grava os órfãos.
	 * As subpastas (dentro das pastas de data) entram na fila.
	 */
	private function scan_dir_for_orphans( $basedir, $dir, array &$queue ) {
		global $wpdb;
		$prefix  = '' === $dir ? '' : $dir . '/';
		$entries = @scandir( $basedir . $prefix ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $entries ) ) {
			return;
		}
		$files = array(); // caminho relativo => array( bytes, data de modificação )
		foreach ( $entries as $e ) {
			if ( '.' === $e || '..' === $e ) {
				continue;
			}
			$abs = $basedir . $prefix . $e;
			if ( is_link( $abs ) ) {
				continue; // Nunca segue links simbólicos.
			}
			$rel = $prefix . $e;
			if ( is_dir( $abs ) ) {
				if ( '' !== $dir && 'hmc-quarantine' !== $e ) {
					$queue[] = $rel;
				}
				continue;
			}
			$size = @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $size || false === HMC_Util::safe_rel( $rel ) ) {
				continue;
			}
			$files[ $rel ] = array( (int) $size, (int) @filemtime( $abs ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! $files ) {
			return;
		}

		// Chaves que interessam: os próprios arquivos e os possíveis "arquivos base" de que derivam.
		$wanted = array();
		foreach ( $files as $rel => $info ) {
			$wanted[ HMC_Orphans::key( $rel ) ] = true;
			$ask                                = array();
			HMC_Orphans::kind_for(
				$rel,
				1,
				function ( $r ) use ( &$ask ) {
					$ask[] = $r;
					return false;
				},
				function () {
					return false;
				}
			);
			foreach ( $ask as $r ) {
				$wanted[ HMC_Orphans::key( $r ) ] = true;
			}
		}
		$known    = $this->lookup( HMC_DB::KIND_KNOWN, array_keys( $wanted ) ); // Uma consulta em lotes por pasta.
		$is_known = function ( $r ) use ( $known ) {
			return isset( $known[ HMC_Orphans::key( $r ) ] );
		};

		$kinds = array();
		$loose = array();
		foreach ( $files as $rel => $info ) {
			if ( isset( $known[ HMC_Orphans::key( $rel ) ] ) ) {
				continue; // Algum anexo o registra: não é órfão.
			}
			$kind = HMC_Orphans::kind_for(
				$rel,
				$info[0],
				$is_known,
				function () {
					return false;
				}
			);
			if ( null === $kind ) {
				continue;
			}
			$kinds[ $rel ] = $kind;
			if ( HMC_Orphans::LOOSE === $kind ) {
				$loose[ $rel ] = HMC_Refs::file_key( $rel );
			}
		}
		// Arquivo solto que o site referencia (URL no conteúdo, no tema...) não é candidato.
		if ( $loose ) {
			$used = $this->lookup( HMC_DB::KIND_FILE, array_values( $loose ) );
			foreach ( $loose as $rel => $k ) {
				if ( isset( $used[ $k ] ) ) {
					$kinds[ $rel ] = HMC_Orphans::IN_USE;
				}
			}
		}

		$table = HMC_DB::orphans_table();
		foreach ( array_chunk( array_keys( $kinds ), 300 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $rel ) {
				$values[] = $wpdb->prepare( '(%s,%s,%d,%s,%s)', HMC_Orphans::key( $rel ), substr( $rel, 0, 400 ), $files[ $rel ][0], gmdate( 'Y-m-d H:i:s', $files[ $rel ][1] ), $kinds[ $rel ] );
			}
			$wpdb->query( "REPLACE INTO {$table} (path_key,path,bytes,mtime,kind) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/* ---------------------------------------------------------------- duplicatas */

	private function step_dup_prepare( array &$s ) {
		global $wpdb;
		$t = HMC_DB::items_table();
		// Só vale comparar conteúdo de arquivos com exatamente o mesmo tamanho.
		$wpdb->query(
			"UPDATE {$t} i INNER JOIN (
				SELECT orig_bytes FROM (SELECT orig_bytes FROM {$t} WHERE orig_bytes > 0 AND q_status = '' GROUP BY orig_bytes HAVING COUNT(*) > 1) x
			) d ON d.orig_bytes = i.orig_bytes SET i.hash = 'pending' WHERE i.q_status = ''"
		); // phpcs:ignore WordPress.DB.PreparedSQL
		return false;
	}

	/**
	 * 1ª fase: lê só o início (64 KB) dos arquivos com tamanho igual. Arquivos pequenos já recebem o hash
	 * completo. Isso evita ler, por inteiro, milhares de fotos que só têm o mesmo tamanho por coincidência.
	 */
	private function step_dup_quick( array &$s ) {
		global $wpdb;
		$t = HMC_DB::items_table();
		if ( ! $s['step_total'] ) {
			$s['step_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE hash = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$rows = $wpdb->get_results( "SELECT attachment_id, file, orig_bytes FROM {$t} WHERE hash = 'pending' LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			return false;
		}
		$basedir = HMC_Quarantine::basedir();
		foreach ( $rows as $r ) {
			$rel  = HMC_Util::safe_rel( $r->file );
			$path = false === $rel ? '' : $basedir . $rel;
			$hash = '';
			if ( '' !== $path && HMC_Util::is_plain_file( $path ) && is_readable( $path ) ) {
				if ( (int) $r->orig_bytes <= 65536 ) {
					$hash = (string) md5_file( $path ); // Pequeno: este já é o hash completo.
				} else {
					$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					if ( $fh ) {
						$hash = 'q:' . substr( md5( (string) fread( $fh, 65536 ) ), 0, 30 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					}
				}
			}
			$wpdb->update( $t, array( 'hash' => $hash ), array( 'attachment_id' => $r->attachment_id ) );
		}
		$s['done'] += count( $rows );
		return true;
	}

	/**
	 * Descarta quem não tem par: só segue para o hash completo quem tem o mesmo tamanho E o mesmo início.
	 */
	private function step_dup_filter( array &$s ) {
		global $wpdb;
		$t = HMC_DB::items_table();
		$wpdb->query(
			"UPDATE {$t} i LEFT JOIN (
				SELECT orig_bytes, hash FROM {$t} WHERE hash LIKE 'q:%' GROUP BY orig_bytes, hash HAVING COUNT(*) > 1
			) d ON d.orig_bytes = i.orig_bytes AND d.hash = i.hash
			SET i.hash = IF(d.hash IS NULL, NULL, 'pending') WHERE i.hash LIKE 'q:%'"
		); // phpcs:ignore WordPress.DB.PreparedSQL
		return false;
	}

	private function step_dup_hash( array &$s ) {
		global $wpdb;
		$t = HMC_DB::items_table();
		if ( ! $s['step_total'] ) {
			$s['step_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE hash = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$rows = $wpdb->get_results( "SELECT attachment_id, file FROM {$t} WHERE hash = 'pending' LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			return false;
		}
		$basedir = HMC_Quarantine::basedir();
		foreach ( $rows as $r ) {
			$rel  = HMC_Util::safe_rel( $r->file );
			$path = false === $rel ? '' : $basedir . $rel;
			$hash = ( '' !== $path && HMC_Util::is_plain_file( $path ) && is_readable( $path ) ) ? (string) md5_file( $path ) : '';
			$wpdb->update( $t, array( 'hash' => $hash ), array( 'attachment_id' => $r->attachment_id ) );
		}
		$s['done'] += count( $rows );
		return true;
	}

	private function step_finish( array &$s ) {
		global $wpdb;
		$t = HMC_DB::items_table();
		// Em cada grupo de arquivos idênticos, a "original" é a primeira em uso; se nenhuma estiver em uso, a mais antiga.
		$wpdb->query(
			"UPDATE {$t} i INNER JOIN (
				SELECT * FROM (
					SELECT hash, COALESCE(MIN(CASE WHEN status = 'used' THEN attachment_id END), MIN(attachment_id)) AS canon
					FROM {$t} WHERE hash IS NOT NULL AND hash NOT IN ('', 'pending') GROUP BY hash HAVING COUNT(*) > 1
				) x
			) g ON g.hash = i.hash
			SET i.dup_of = IF(i.attachment_id = g.canon, NULL, g.canon)"
		); // phpcs:ignore WordPress.DB.PreparedSQL

		$summary = array(
			'finished' => time(),
			'duration' => time() - (int) $s['started'],
			'status'   => array(),
			'total'    => array( 'count' => 0, 'bytes' => 0 ),
		);
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) n, SUM(bytes) b FROM {$t} WHERE q_status = '' GROUP BY status" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$summary['status'][ $r->status ] = array( 'count' => (int) $r->n, 'bytes' => (int) $r->b );
			$summary['total']['count']      += (int) $r->n;
			$summary['total']['bytes']      += (int) $r->b;
		}
		$q                     = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b, COALESCE(SUM(status = 'used' OR q_hits > 0),0) a FROM {$t} WHERE q_status = 'quarantined'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$summary['quarantine'] = array( 'count' => (int) $q->n, 'bytes' => (int) $q->b, 'alert' => (int) $q->a );
		$summary['total']['count'] += (int) $q->n;
		$summary['total']['bytes'] += (int) $q->b;
		$summary['front_errors']    = (int) get_option( 'hmc_front_errors', 0 );
		$dup_unused            = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM {$t} WHERE dup_of IS NOT NULL AND status <> 'used' AND q_status = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$dup_used              = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM {$t} WHERE dup_of IS NOT NULL AND status = 'used' AND q_status = ''" );   // phpcs:ignore WordPress.DB.PreparedSQL
		$summary['dup_unused'] = array( 'count' => (int) $dup_unused->n, 'bytes' => (int) $dup_unused->b );
		$summary['dup_used']   = array( 'count' => (int) $dup_used->n, 'bytes' => (int) $dup_used->b );
		$summary['refs']       = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . HMC_DB::refs_table() . ' WHERE kind IN (' . (int) HMC_DB::KIND_ID . ',' . (int) HMC_DB::KIND_FILE . ')' ); // Só referências reais (não os caminhos registrados). phpcs:ignore WordPress.DB.PreparedSQL

		$orph = array();
		foreach ( $wpdb->get_results( 'SELECT kind, COUNT(*) n, COALESCE(SUM(bytes),0) b FROM ' . HMC_DB::orphans_table() . ' GROUP BY kind' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$orph[ $r->kind ] = array( 'count' => (int) $r->n, 'bytes' => (int) $r->b );
		}
		update_option( 'hmc_orphan_summary', array( 'finished' => time(), 'kinds' => $orph ), false );

		update_option( 'hmc_summary', $summary, false );
		return false;
	}
}
