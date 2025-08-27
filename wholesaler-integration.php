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
 * Author:            MD Jahidul Islam Sabuz
 * Author URI:        https://https://github.com/coderjahidul/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wholesaler-integration
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'WHOLESALER_INTEGRATION_VERSION', '1.0.0' );

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
require plugin_dir_path( __FILE__ ) . 'includes/class-wholesaler-integration.php';

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

// Function to append data to a log file
function put_program_logs( $data ) {

    // Ensure the directory for logs exists
    $directory = __DIR__ . '/program_logs/';
    if ( ! file_exists( $directory ) ) {
        // Use wp_mkdir_p instead of mkdir
        if ( ! wp_mkdir_p( $directory ) ) {
            return "Failed to create directory.";
        }
    }

    // Construct the log file path
    $file_name = $directory . 'program_logs.log';

    // Append the current datetime to the log entry
    $current_datetime = gmdate( 'Y-m-d H:i:s' ); // Use gmdate instead of date
    $data             = $data . ' - ' . $current_datetime;

    // Write the log entry to the file
    if ( file_put_contents( $file_name, $data . "\n\n", FILE_APPEND | LOCK_EX ) !== false ) {
        return "Data appended to file successfully.";
    } else {
        return "Failed to append data to file.";
    }
}