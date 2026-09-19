<?php
/**
 * Persistence for pricing rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Rule_Repository {

	/**
	 * Return the fully-prefixed rules table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wem_price_rules';
	}

	/**
	 * Create or migrate the rules table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			condition_type varchar(32) NOT NULL,
			condition_key varchar(191) NOT NULL DEFAULT '',
			condition_value longtext NOT NULL,
			action_type varchar(32) NOT NULL,
			amount decimal(20,4) NOT NULL DEFAULT 0,
			price_target varchar(20) NOT NULL DEFAULT 'regular',
			round_to bigint(20) unsigned NOT NULL DEFAULT 1,
			is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			last_run_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY is_active (is_active),
			KEY condition_type (condition_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Fetch all rules.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY is_active DESC, updated_at DESC, id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Fetch one rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return array|null
	 */
	public static function get( $rule_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', absint( $rule_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Insert or update a pricing rule.
	 *
	 * @param array $rule Rule data.
	 * @return int|WP_Error
	 */
	public static function save( array $rule ) {
		global $wpdb;

		$now     = current_time( 'mysql' );
		$rule_id = isset( $rule['id'] ) ? absint( $rule['id'] ) : 0;
		$payload = array(
			'name'            => sanitize_text_field( $rule['name'] ),
			'condition_type'  => sanitize_key( $rule['condition_type'] ),
			'condition_key'   => sanitize_key( isset( $rule['condition_key'] ) ? $rule['condition_key'] : '' ),
			'condition_value' => wp_json_encode(
				array(
					'values'           => array_values( isset( $rule['condition_values'] ) ? (array) $rule['condition_values'] : array() ),
					'include_children' => ! empty( $rule['include_children'] ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'action_type'     => sanitize_key( $rule['action_type'] ),
			'amount'          => (string) $rule['amount'],
			'price_target'    => sanitize_key( $rule['price_target'] ),
			'round_to'        => max( 1, absint( $rule['round_to'] ) ),
			'is_active'       => ! empty( $rule['is_active'] ) ? 1 : 0,
			'updated_at'      => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

		if ( $rule_id ) {
			$result = $wpdb->update( self::table_name(), $payload, array( 'id' => $rule_id ), $formats, array( '%d' ) );
		} else {
			$payload['created_by'] = get_current_user_id();
			$payload['created_at'] = $now;
			$formats[]             = '%d';
			$formats[]             = '%s';
			$result                = $wpdb->insert( self::table_name(), $payload, $formats );
			$rule_id               = (int) $wpdb->insert_id;
		}

		if ( false === $result ) {
			return new WP_Error( 'wem_rule_save_failed', $wpdb->last_error ? $wpdb->last_error : 'ذخیره قانون انجام نشد.' );
		}

		return $rule_id;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return bool
	 */
	public static function delete( $rule_id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table_name(), array( 'id' => absint( $rule_id ) ), array( '%d' ) );
	}

	/**
	 * Toggle a rule's active state.
	 *
	 * @param int $rule_id Rule ID.
	 * @return bool
	 */
	public static function toggle( $rule_id ) {
		global $wpdb;

		$rule = self::get( $rule_id );
		if ( ! $rule ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table_name(),
			array(
				'is_active' => empty( $rule['is_active'] ) ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $rule_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a rule as executed.
	 *
	 * @param int $rule_id Rule ID.
	 * @return void
	 */
	public static function touch_last_run( $rule_id ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'last_run_at' => current_time( 'mysql' ) ),
			array( 'id' => absint( $rule_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count enabled rules.
	 *
	 * @return int
	 */
	public static function count_active() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE is_active = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Hydrate stored JSON into the public rule shape.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function hydrate( array $row ) {
		$condition = json_decode( isset( $row['condition_value'] ) ? $row['condition_value'] : '', true );
		if ( ! is_array( $condition ) ) {
			$condition = array( 'values' => array(), 'include_children' => false );
		}

		$row['id']               = absint( $row['id'] );
		$row['amount']           = (string) $row['amount'];
		$row['round_to']         = max( 1, absint( $row['round_to'] ) );
		$row['is_active']        = ! empty( $row['is_active'] );
		$row['condition_values'] = isset( $condition['values'] ) ? array_values( (array) $condition['values'] ) : array();
		$row['include_children'] = ! empty( $condition['include_children'] );
		unset( $row['condition_value'] );

		return $row;
	}
}
