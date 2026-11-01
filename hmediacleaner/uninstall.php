<?php
/**
 * Desinstalação: primeiro devolve à biblioteca de mídia tudo o que estiver em quarentena,
 * depois remove as tabelas e opções do plugin. A biblioteca de mídia não perde nada.
 */

defined( 'ABSPATH' ) || exit;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-hmc-util.php';
require_once __DIR__ . '/includes/class-hmc-db.php';
require_once __DIR__ . '/includes/class-hmc-refs.php';
require_once __DIR__ . '/includes/class-hmc-quarantine.php';

if ( ! defined( 'HMC_VERSION' ) ) {
	define( 'HMC_VERSION', 'uninstall' );
}

if ( function_exists( 'set_time_limit' ) ) {
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

list( $hmc_ok, $hmc_fail ) = HMC_Quarantine::restore_all();

// Se algo não pôde ser restaurado, os dados ficam para não perder o controle desses arquivos.
if ( $hmc_fail ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hmc_refs" );  // phpcs:ignore WordPress.DB.PreparedSQL
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hmc_items" ); // phpcs:ignore WordPress.DB.PreparedSQL
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hmc_orphans" ); // phpcs:ignore WordPress.DB.PreparedSQL
foreach ( array( 'hmc_state', 'hmc_summary', 'hmc_settings', 'hmc_db_version', 'hmc_theme_files', 'hmc_front_errors', 'hmc_orphan_queue', 'hmc_orphan_summary' ) as $hmc_opt ) {
	delete_option( $hmc_opt );
}
delete_transient( 'hmc_lock' );
delete_transient( 'hmc_bulk_lock' );

// Transients do atualizador do GitHub.
delete_transient( 'hmc_update_check' );
delete_transient( 'hmc_update_check_branch' );
