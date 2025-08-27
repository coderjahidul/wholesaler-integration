<?php

/**
 * Fired during plugin activation
 *
 * @link       https://https://github.com/coderjahidul
 * @since      1.0.0
 *
 * @package    Wholesaler_Integration
 * @subpackage Wholesaler_Integration/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Wholesaler_Integration
 * @subpackage Wholesaler_Integration/includes
 * @author     MD Jahidul Islam Sabuz <sobuz0349@gmail.com>
 */
class Wholesaler_Integration_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		// create table on activation
		global $wpdb;

		$table_name = $wpdb->prefix . 'wholesaler_products_data';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			wholesaler_name VARCHAR(50) NOT NULL,
			sku VARCHAR(100) NOT NULL,
			wholesale_price DECIMAL(10,2) NOT NULL,
			stock INT(11) NOT NULL DEFAULT 0,
			brand VARCHAR(100) DEFAULT NULL,
			attributes JSON DEFAULT NULL,
			product_data JSON DEFAULT NULL,
			last_synced DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY sku (sku),
			KEY wholesaler_name (wholesaler_name)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

	}

}
