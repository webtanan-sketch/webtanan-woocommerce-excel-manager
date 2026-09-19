<?php
/**
 * Price change audit foundation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Log {

	const TABLE_SUFFIX = 'wem_price_logs';

	/**
	 * Create audit table during plugin upgrade/install phase.
	 */
	public static function install() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			old_price decimal(20,4) NOT NULL DEFAULT 0,
			new_price decimal(20,4) NOT NULL DEFAULT 0,
			change_type varchar(50) NOT NULL,
			rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Prepare audit record.
	 */
	public static function prepare( $product_id, $old, $new, $type, $rule_id = 0 ) {
		return array(
			'product_id' => absint( $product_id ),
			'old_price'  => (float) $old,
			'new_price'  => (float) $new,
			'change_type'=> sanitize_key( $type ),
			'rule_id'    => absint( $rule_id ),
			'user_id'    => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
		);
	}
}
