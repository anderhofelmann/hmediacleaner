<?php
/**
 * Plugin Name:       HMediaCleaner
 * Plugin URI:        https://github.com/anderhofelmann/hmediacleaner
 * Description:       Encontra arquivos da biblioteca de mídia sem uso e duplicados, com análise abrangente do site, e os coloca em quarentena (restaurável) antes de qualquer exclusão.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Anderson Höfelmann
 * Author URI:        https://github.com/anderhofelmann
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hmediacleaner
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'HMC_VERSION', '0.3.0' );
define( 'HMC_FILE', __FILE__ );
define( 'HMC_DIR', plugin_dir_path( __FILE__ ) );
define( 'HMC_URL', plugin_dir_url( __FILE__ ) );

require_once HMC_DIR . 'includes/class-hmc-db.php';
require_once HMC_DIR . 'includes/class-hmc-refs.php';
require_once HMC_DIR . 'includes/class-hmc-scanner.php';
require_once HMC_DIR . 'includes/class-hmc-quarantine.php';
require_once HMC_DIR . 'includes/class-hmc-updater.php';

HMC_Quarantine::init();

register_activation_hook( __FILE__, array( 'HMC_DB', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		HMC_DB::maybe_upgrade();
		HMC_Updater::init();

		if ( is_admin() ) {
			require_once HMC_DIR . 'includes/class-hmc-admin.php';
			new HMC_Admin();
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once HMC_DIR . 'includes/class-hmc-cli.php';
	WP_CLI::add_command( 'hmediacleaner', 'HMC_CLI' );
}
