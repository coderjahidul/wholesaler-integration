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
		global $wpdb;

		$table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			wholesaler_name VARCHAR(10) NOT NULL,
			sku VARCHAR(100) UNIQUE NOT NULL,
			brand VARCHAR(100) DEFAULT NULL,
			product_data JSON NULL,
			status VARCHAR(12) NOT NULL DEFAULT '" . Status_Enum::PENDING->value . "',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

}
