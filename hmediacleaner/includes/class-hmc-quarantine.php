<?php
/**
 * Quarentena: os arquivos de um anexo são movidos (não apagados) para uploads/hmc-quarantine/<ID>/,
 * o anexo sai da biblioteca de mídia (status próprio) e tudo pode ser restaurado exatamente como era.
 *
 * Enquanto em quarentena, pedidos aos arquivos que chegam ao WordPress como 404 são contados,
 * com data e página de origem: é o sinal de que a imagem faz falta.
 */

defined( 'ABSPATH' ) || exit;

class HMC_Quarantine {

	const STATUS = 'hmc_quarantine';
	const META   = '_hmc_quarantine';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_action( 'template_redirect', array( __CLASS__, 'track_404' ), 1 );
	}

	public static function register_status() {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Em quarentena', 'status do anexo', 'hmediacleaner' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	public static function basedir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] );
	}

	public static function dir() {
		return self::basedir() . 'hmc-quarantine';
	}

	public static function ensure_dir() {
		$d = self::dir();
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
		if ( ! file_exists( $d . '/.htaccess' ) ) {
			file_put_contents( $d . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $d . '/index.php' ) ) {
			file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		// IIS: o .htaccess não vale lá. (No Nginx é preciso uma regra no servidor; veja o README.)
		if ( ! file_exists( $d . '/web.config' ) ) {
			file_put_contents( $d . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return is_dir( $d ) && wp_is_writable( $d );
	}

	/**
	 * Arquivos de um anexo que existem no disco, relativos ao diretório de uploads: arquivo principal,
	 * miniaturas, original antes do -scaled, cópias de edição, cópia de compressão e versões WebP.
	 */
	public static function attachment_files( $id, $base = null ) {
		// Os caminhos vêm do banco e não são confiáveis: qualquer "../" ou caminho absoluto é descartado.
		$file = HMC_Util::safe_rel( get_post_meta( $id, '_wp_attached_file', true ) );
		if ( false === $file ) {
			return array();
		}
		$base   = null === $base ? self::basedir() : trailingslashit( $base );
		$dir    = dirname( $file );
		$prefix = ( '.' === $dir ) ? '' : trailingslashit( $dir );
		$md     = get_post_meta( $id, '_wp_attachment_metadata', true );
		$names  = array( basename( $file ) );
		if ( is_array( $md ) ) {
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
		}
		$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backup ) ) {
			foreach ( $backup as $size ) {
				$n = is_array( $size ) && isset( $size['file'] ) ? HMC_Util::safe_name( $size['file'] ) : false;
				if ( false !== $n ) {
					$names[] = $n;
				}
			}
		}
		$info    = pathinfo( basename( $file ) );
		$names[] = $info['filename'] . '-updraft-pre-smush-original.' . ( isset( $info['extension'] ) ? $info['extension'] : '' );

		$all = array();
		foreach ( array_unique( $names ) as $n ) {
			$all[] = $n;
			$all[] = $n . '.webp';
			$all[] = preg_replace( '/\.[^.]+$/', '.webp', $n );
		}
		$out = array();
		foreach ( array_unique( $all ) as $n ) {
			$rel = HMC_Util::safe_rel( $prefix . $n );
			// Só arquivos comuns que, resolvidos os links simbólicos, estão mesmo dentro de uploads.
			if ( false !== $rel && 0 !== strpos( $rel, 'hmc-quarantine/' ) && HMC_Util::is_plain_file( $base . $rel ) && HMC_Util::inside( $base . $rel, $base ) ) {
				$out[] = $rel;
			}
		}
		return $out;
	}

	/**
	 * Checagem rápida, no momento da ação, dos usos mais comuns por ID (foto principal, galeria,
	 * miniatura de categoria, logo e ícone do site). Retorna os IDs que estão em uso agora.
	 */
	public static function live_refs( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = "'" . implode( "','", $ids ) . "'";
		$used = array();
		foreach ( $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$in})" ) as $v ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$used[ (int) $v ] = true;
		}
		foreach ( $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value IN ({$in})" ) as $v ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$used[ (int) $v ] = true;
		}
		static $gallery = null;
		if ( null === $gallery ) {
			$gallery = array();
			foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''" ) as $list ) {
				foreach ( explode( ',', $list ) as $g ) {
					$gallery[ (int) $g ] = true;
				}
			}
		}
		foreach ( array( get_option( 'site_icon' ), get_theme_mod( 'custom_logo' ), get_option( 'woocommerce_placeholder_image' ) ) as $v ) {
			if ( is_numeric( $v ) ) {
				$used[ (int) $v ] = true;
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $used[ $id ] ) || isset( $gallery[ $id ] ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function is_quarantined( $id ) {
		return is_array( get_post_meta( $id, self::META, true ) );
	}

	/**
	 * @return true|string true ou mensagem de erro.
	 */
	public static function quarantine( $id ) {
		global $wpdb;
		$id   = (int) $id;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return __( 'Anexo não encontrado.', 'hmediacleaner' );
		}
		if ( self::is_quarantined( $id ) ) {
			return __( 'Já está em quarentena.', 'hmediacleaner' );
		}
		if ( ! self::ensure_dir() ) {
			return __( 'Não foi possível criar a pasta de quarentena (verifique as permissões de uploads).', 'hmediacleaner' );
		}
		$files = self::attachment_files( $id );
		if ( ! $files ) {
			return __( 'Nenhum arquivo deste anexo foi encontrado no disco.', 'hmediacleaner' );
		}

		$base  = self::basedir();
		$qbase = self::dir() . '/' . $id . '/';
		$moved = array();
		foreach ( $files as $rel ) {
			$dst = $qbase . $rel;
			wp_mkdir_p( dirname( $dst ) );
			// O destino precisa ficar, de fato, dentro da pasta da quarentena.
			if ( ! HMC_Util::dir_inside( dirname( $dst ), self::dir() ) || file_exists( $dst ) || ! @rename( $base . $rel, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( $moved as $back ) {
					@rename( $qbase . $back, $base . $back ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				self::rmdir_tree( $qbase );
				/* translators: %s: arquivo */
				return sprintf( __( 'Falha ao mover %s; nada foi alterado.', 'hmediacleaner' ), $rel );
			}
			$moved[] = $rel;
		}

		update_post_meta(
			$id,
			self::META,
			array(
				'date'     => time(),
				'files'    => $moved,
				'status'   => $post->post_status,
				'user'     => get_current_user_id(),
				'hits'     => 0,
				'last_hit' => 0,
				'referer'  => '',
			)
		);
		$wpdb->update( $wpdb->posts, array( 'post_status' => self::STATUS ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$wpdb->update(
			HMC_DB::items_table(),
			array(
				'q_status'   => 'quarantined',
				'q_date'     => current_time( 'mysql' ),
				'q_hits'     => 0,
				'q_last_hit' => null,
				'q_referer'  => '',
			),
			array( 'attachment_id' => $id )
		);
		self::log( 'quarentena', $id, $moved );
		return true;
	}

	/**
	 * @return true|string
	 */
	public static function restore( $id ) {
		global $wpdb;
		$id = (int) $id;
		$q  = get_post_meta( $id, self::META, true );
		if ( ! is_array( $q ) || empty( $q['files'] ) ) {
			return __( 'Este anexo não está em quarentena.', 'hmediacleaner' );
		}
		$base  = self::basedir();
		$qbase = self::dir() . '/' . $id . '/';

		// Os caminhos gravados na quarentena vêm do banco: se algum for suspeito (por exemplo com
		// "../"), nada é movido, para a restauração nunca escrever fora de uploads.
		$files = array();
		foreach ( (array) $q['files'] as $rel ) {
			$ok = HMC_Util::safe_rel( $rel );
			if ( false === $ok ) {
				return __( 'Os dados da quarentena deste anexo são inválidos; a restauração foi cancelada por segurança.', 'hmediacleaner' );
			}
			$files[] = $ok;
		}
		foreach ( $files as $rel ) {
			if ( file_exists( $base . $rel ) ) {
				/* translators: %s: arquivo */
				return sprintf( __( 'Já existe um arquivo em %s; a restauração foi cancelada para não sobrescrevê-lo.', 'hmediacleaner' ), $rel );
			}
		}
		$done = array();
		foreach ( $files as $rel ) {
			if ( ! HMC_Util::is_plain_file( $qbase . $rel ) || ! HMC_Util::inside( $qbase . $rel, self::dir() ) ) {
				continue; // Arquivo já ausente (ou suspeito) na quarentena: restaura o que for seguro.
			}
			wp_mkdir_p( dirname( $base . $rel ) );
			if ( ! HMC_Util::dir_inside( dirname( $base . $rel ), $base ) || ! @rename( $qbase . $rel, $base . $rel ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( $done as $back ) {
					@rename( $base . $back, $qbase . $back ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				/* translators: %s: arquivo */
				return sprintf( __( 'Falha ao restaurar %s; nada foi alterado.', 'hmediacleaner' ), $rel );
			}
			$done[] = $rel;
		}
		$wpdb->update( $wpdb->posts, array( 'post_status' => ! empty( $q['status'] ) && self::STATUS !== $q['status'] ? $q['status'] : 'inherit' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		delete_post_meta( $id, self::META );
		self::rmdir_tree( $qbase );
		$wpdb->update( HMC_DB::items_table(), array( 'q_status' => 'restored' ), array( 'attachment_id' => $id ) );
		self::log( 'restaurado', $id, $done );
		return true;
	}

	/**
	 * Exclusão definitiva, só para itens em quarentena há pelo menos $min_days dias.
	 *
	 * @return true|string
	 */
	public static function delete( $id, $min_days ) {
		global $wpdb;
		$id = (int) $id;
		$q  = get_post_meta( $id, self::META, true );
		if ( ! is_array( $q ) ) {
			return __( 'Só é possível excluir definitivamente arquivos que estão em quarentena.', 'hmediacleaner' );
		}
		if ( time() - (int) $q['date'] < (int) $min_days * DAY_IN_SECONDS ) {
			/* translators: %d: dias */
			return sprintf( __( 'Está em quarentena há menos de %d dias.', 'hmediacleaner' ), (int) $min_days );
		}
		$file = get_post_meta( $id, '_wp_attached_file', true );
		if ( ! wp_delete_attachment( $id, true ) ) {
			return __( 'O WordPress não conseguiu excluir o anexo.', 'hmediacleaner' );
		}
		self::rmdir_tree( self::dir() . '/' . $id . '/' );
		$wpdb->update( HMC_DB::items_table(), array( 'q_status' => 'deleted' ), array( 'attachment_id' => $id ) );
		self::log( 'excluido', $id, isset( $q['files'] ) ? $q['files'] : array( $file ) );
		return true;
	}

	/**
	 * Conta pedidos 404 a arquivos em quarentena (só os que chegam ao WordPress).
	 */
	public static function track_404() {
		global $wpdb;
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$seg = '/' . basename( untrailingslashit( self::basedir() ) ) . '/';
		$pos = strpos( $uri, $seg );
		if ( false === $pos ) {
			return;
		}
		$rel = strtok( substr( $uri, $pos + strlen( $seg ) ), '?#' );
		$t   = HMC_DB::items_table();
		$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attachment_id FROM {$t} WHERE fkey = %s AND q_status = 'quarantined' LIMIT 1", HMC_Refs::file_key( $rel ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $id ) {
			// Não é de um anexo: pode ser de um arquivo órfão em quarentena.
			HMC_Orphan_Quarantine::track_404( $rel );
			return;
		}
		// Qualquer visitante pode pedir esse endereço: no máximo uma contagem a cada 5 minutos por
		// arquivo, para não permitir escrita repetida no banco nem inflar o alerta de "falta".
		if ( get_transient( 'hmc_hit_' . $id ) ) {
			return;
		}
		set_transient( 'hmc_hit_' . $id, 1, 5 * MINUTE_IN_SECONDS );
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 255 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET q_hits = q_hits + 1, q_last_hit = %s, q_referer = %s WHERE attachment_id = %d", current_time( 'mysql' ), $referer, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$q = get_post_meta( $id, self::META, true );
		if ( is_array( $q ) ) {
			$q['hits']     = (int) $q['hits'] + 1;
			$q['last_hit'] = time();
			$q['referer']  = $referer;
			update_post_meta( $id, self::META, $q );
		}
	}

	public static function log( $action, $id, array $files ) {
		if ( ! self::ensure_dir() ) {
			return;
		}
		$user = wp_get_current_user();
		$line = array( wp_date( 'Y-m-d H:i:s' ), $user && $user->exists() ? $user->user_login : 'sistema', $action, $id, implode( ' | ', $files ) );
		$fh   = fopen( self::dir() . '/hmc-log.csv', 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh ) {
			fputcsv( $fh, HMC_Util::csv_row( $line ), ';' );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Remove uma pasta e o conteúdo, apenas dentro da pasta de quarentena.
	 */
	private static function rmdir_tree( $path ) {
		$root = realpath( self::dir() );
		$real = realpath( $path );
		if ( ! $root || ! $real || $real === $root || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Restaura todos os anexos em quarentena (usado na desinstalação). Retorna [restaurados, falhas].
	 */
	public static function restore_all() {
		global $wpdb;
		$ok   = 0;
		$fail = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ) as $id ) {
			true === self::restore( $id ) ? $ok++ : $fail++;
		}
		return array( $ok, $fail );
	}
}
