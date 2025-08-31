<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://https://github.com/coderjahidul
 * @since             1.0.0
 * @package           Wholesaler_Integration
 *
 * @wordpress-plugin
 * Plugin Name:       Wholesaler Integration
 * Plugin URI:        https://https://github.com/coderjahidul/wholesaler-integration
 * Description:       Sync products/stock/prices from JS, MADA, AREN wholesalers to WooCommerce.
 * Version:           1.0.0
 * Author:            Sujon
 * Author URI:        https://github.com/mtmsujan
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wholesaler-integration
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'WHOLESALER_INTEGRATION_VERSION', '1.0.0' );

// Define plugin path
if ( !defined( 'WHOLESALER_PLUGIN_PATH' ) ) {
    define( 'WHOLESALER_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
}

// Define plugin url
if ( !defined( 'WHOLESALER_PLUGIN_URL' ) ) {
    define( 'WHOLESALER_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-wholesaler-integration-activator.php
 */
function activate_wholesaler_integration() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wholesaler-integration-activator.php';
    Wholesaler_Integration_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-wholesaler-integration-deactivator.php
 */
function deactivate_wholesaler_integration() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wholesaler-integration-deactivator.php';
    Wholesaler_Integration_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_wholesaler_integration' );
register_deactivation_hook( __FILE__, 'deactivate_wholesaler_integration' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/enums/status-enum.php';
require plugin_dir_path( __FILE__ ) . 'includes/helpers/helper.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-wholesaler-integration.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-wholesaler-brands-api.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-wholesaler-integration-import-products.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_wholesaler_integration() {

    $plugin = new Wholesaler_Integration();
    $plugin->run();

}
run_wholesaler_integration();
