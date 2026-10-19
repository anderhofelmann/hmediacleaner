<?php
/**
 * Tabelas do HMediaCleaner.
 *
 * hmc_refs  – todas as referências a mídia encontradas no site (IDs e caminhos de arquivo).
 * hmc_items – resultado da análise, uma linha por anexo da biblioteca de mídia.
 *
 * A situação de quarentena fica gravada no próprio anexo (post meta _hmc_quarantine), que é a
 * fonte confiável; as colunas q_* de hmc_items são uma cópia para consulta rápida.
 */

defined( 'ABSPATH' ) || exit;

class HMC_DB {

	const DB_VERSION = '2';

	const KIND_ID   = 1;
	const KIND_FILE = 2;

	public static function refs_table() {
		global $wpdb;
		return $wpdb->prefix . 'hmc_refs';
	}

	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'hmc_items';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$refs    = self::refs_table();
		$items   = self::items_table();

		dbDelta(
			"CREATE TABLE {$refs} (
			kind tinyint(1) unsigned NOT NULL,
			ref char(32) NOT NULL,
			src varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (kind,ref)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
			attachment_id bigint(20) unsigned NOT NULL,
			file varchar(255) NOT NULL DEFAULT '',
			fkey char(32) NOT NULL DEFAULT '',
			mime varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			reason varchar(255) NOT NULL DEFAULT '',
			used_in varchar(191) NOT NULL DEFAULT '',
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			uploaded datetime NULL DEFAULT NULL,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			orig_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			files_count int(10) unsigned NOT NULL DEFAULT 0,
			hash varchar(32) NULL DEFAULT NULL,
			dup_of bigint(20) unsigned NULL DEFAULT NULL,
			q_status varchar(20) NOT NULL DEFAULT '',
			q_date datetime NULL DEFAULT NULL,
			q_hits int(10) unsigned NOT NULL DEFAULT 0,
			q_last_hit datetime NULL DEFAULT NULL,
			q_referer varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (attachment_id),
			KEY status (status),
			KEY hash (hash),
			KEY orig_bytes (orig_bytes),
			KEY dup_of (dup_of),
			KEY fkey (fkey),
			KEY q_status (q_status)
			) {$collate};"
		);

		update_option( 'hmc_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'hmc_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Rótulos dos status, na ordem em que aparecem no painel.
	 */
	public static function statuses() {
		return array(
			'unused'   => __( 'Sem uso', 'hmediacleaner' ),
			'attached' => __( 'Provavelmente sem uso', 'hmediacleaner' ),
			'missing'  => __( 'Arquivo ausente', 'hmediacleaner' ),
			'recent'   => __( 'Recente (protegida)', 'hmediacleaner' ),
			'used'     => __( 'Em uso', 'hmediacleaner' ),
		);
	}

	public static function settings() {
		$s = wp_parse_args(
			get_option( 'hmc_settings', array() ),
			array(
				'protect_days'   => 7,
				'scan_max_age'   => 24,
				'retention_days' => 30,
				'theme_files'    => 1,
				'frontend'       => 1,
			)
		);
		foreach ( $s as $k => $v ) {
			$s[ $k ] = (int) $v;
		}
		return $s;
	}

	/**
	 * A análise mais recente está concluída e é nova o bastante para basear uma quarentena?
	 *
	 * @return true|string true ou o motivo do bloqueio.
	 */
	public static function scan_is_fresh() {
		$state   = HMC_Scanner::state();
		$summary = get_option( 'hmc_summary' );
		if ( '' !== $state['step'] && ! $state['finished'] ) {
			return __( 'Há uma análise em andamento. Aguarde a conclusão.', 'hmediacleaner' );
		}
		if ( ! $summary ) {
			return __( 'Faça uma análise antes de usar a quarentena.', 'hmediacleaner' );
		}
		$max = self::settings()['scan_max_age'];
		if ( $max > 0 && time() - (int) $summary['finished'] > $max * HOUR_IN_SECONDS ) {
			/* translators: %d: horas */
			return sprintf( __( 'A última análise tem mais de %d horas. Faça uma nova análise antes de colocar arquivos em quarentena, para considerar as mudanças recentes do site.', 'hmediacleaner' ), $max );
		}
		return true;
	}
}
