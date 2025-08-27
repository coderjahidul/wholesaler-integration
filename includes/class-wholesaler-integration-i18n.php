<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://https://github.com/coderjahidul
 * @since      1.0.0
 *
 * @package    Wholesaler_Integration
 * @subpackage Wholesaler_Integration/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Wholesaler_Integration
 * @subpackage Wholesaler_Integration/includes
 * @author     MD Jahidul Islam Sabuz <sobuz0349@gmail.com>
 */
class Wholesaler_Integration_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'wholesaler-integration',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
