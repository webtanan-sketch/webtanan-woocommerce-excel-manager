<?php
/**
 * Price change audit logger foundation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Log {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wem_price_logs';
	}

	public static function install() {
		global $wpdb;

		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned DEFAULT 0,
			old_price decimal(20,4) DEFAULT 0,
			new_price decimal(20,4) DEFAULT 0,
			change_type varchar(50) NOT NULL,
			rule_id bigint(20) unsigned DEFAULT 0,
			user_id bigint(20) unsigned DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY product_id (product_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function add( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'product_id' => absint( $data['product_id'] ?? 0 ),
				'variation_id' => absint( $data['variation_id'] ?? 0 ),
				'old_price' => $data['old_price'] ?? 0,
				'new_price' => $data['new_price'] ?? 0,
				'change_type' => sanitize_key( $data['change_type'] ?? 'manual' ),
				'rule_id' => absint( $data['rule_id'] ?? 0 ),
				'user_id' => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}
}
