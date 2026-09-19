<?php
/**
 * Price change audit log and guarded rollback service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Log {

	/**
	 * Return the fully-prefixed audit table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wem_price_logs';
	}

	/**
	 * Create or migrate the audit table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_id varchar(64) NOT NULL DEFAULT '',
			operation_label varchar(191) NOT NULL DEFAULT '',
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			price_field varchar(32) NOT NULL DEFAULT 'regular_price',
			old_price varchar(50) NOT NULL DEFAULT '',
			new_price varchar(50) NOT NULL DEFAULT '',
			change_type varchar(50) NOT NULL,
			rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			context longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			rolled_back_at datetime DEFAULT NULL,
			rolled_back_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY operation_id (operation_id),
			KEY product_id (product_id),
			KEY rule_id (rule_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate a sortable, collision-resistant operation identifier.
	 *
	 * @param string $prefix Identifier prefix.
	 * @return string
	 */
	public static function new_operation_id( $prefix = 'operation' ) {
		$prefix = sanitize_key( $prefix );
		return substr( $prefix . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 8, false, false ) ), 0, 64 );
	}

	/**
	 * Insert one field-level audit row.
	 *
	 * @param array $data Audit data.
	 * @return int|WP_Error
	 */
	public static function add( array $data ) {
		global $wpdb;

		$product_id   = absint( isset( $data['product_id'] ) ? $data['product_id'] : 0 );
		$variation_id = absint( isset( $data['variation_id'] ) ? $data['variation_id'] : 0 );
		$price_field  = isset( $data['price_field'] ) && 'sale_price' === $data['price_field'] ? 'sale_price' : 'regular_price';
		if ( ! $product_id ) {
			return new WP_Error( 'wem_log_product_missing', 'شناسه محصول برای ثبت تاریخچه معتبر نیست.' );
		}

		$context = isset( $data['context'] ) ? wp_json_encode( $data['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
		$result  = $wpdb->insert(
			self::table_name(),
			array(
				'operation_id'    => sanitize_key( isset( $data['operation_id'] ) ? $data['operation_id'] : self::new_operation_id() ),
				'operation_label' => sanitize_text_field( isset( $data['operation_label'] ) ? $data['operation_label'] : '' ),
				'product_id'      => $product_id,
				'variation_id'    => $variation_id,
				'price_field'     => $price_field,
				'old_price'       => self::canonical_decimal( isset( $data['old_price'] ) ? $data['old_price'] : '' ),
				'new_price'       => self::canonical_decimal( isset( $data['new_price'] ) ? $data['new_price'] : '' ),
				'change_type'     => sanitize_key( isset( $data['change_type'] ) ? $data['change_type'] : 'manual' ),
				'rule_id'         => absint( isset( $data['rule_id'] ) ? $data['rule_id'] : 0 ),
				'user_id'         => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : get_current_user_id(),
				'context'         => $context,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'wem_log_insert_failed', $wpdb->last_error ? $wpdb->last_error : 'ثبت تاریخچه تغییر قیمت انجام نشد.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Record every changed price field for a product.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $changes Field changes.
	 * @param array      $context Operation context.
	 * @return true|WP_Error
	 */
	public static function record_changes( $product, array $changes, array $context ) {
		$object_id = $product->get_id();
		$parent_id = $product->get_parent_id();

		foreach ( $changes as $field => $change ) {
			if ( ! in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) {
				continue;
			}
			$result = self::add(
				array_merge(
					$context,
					array(
						'product_id'   => $parent_id ? $parent_id : $object_id,
						'variation_id' => $parent_id ? $object_id : 0,
						'price_field'  => $field,
						'old_price'    => isset( $change['old'] ) ? $change['old'] : '',
						'new_price'    => isset( $change['new'] ) ? $change['new'] : '',
					)
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Fetch operation summaries for the history screen.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function operations( $limit = 25, $offset = 0 ) {
		global $wpdb;

		$limit  = min( 100, max( 1, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );
		$table  = self::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal, prefix-derived table name; values still use placeholders.
		$sql    = $wpdb->prepare(
			"SELECT operation_id, MAX(operation_label) AS operation_label, MAX(change_type) AS change_type,
			 MAX(rule_id) AS rule_id, MAX(user_id) AS user_id, COUNT(*) AS change_count,
			 COUNT(DISTINCT CASE WHEN variation_id > 0 THEN variation_id ELSE product_id END) AS product_count,
			 SUM(CASE WHEN rolled_back_at IS NOT NULL THEN 1 ELSE 0 END) AS rolled_back_count,
			 MAX(created_at) AS created_at, MAX(id) AS last_id
			 FROM {$table}
			 WHERE operation_id <> ''
			 GROUP BY operation_id
			 ORDER BY last_id DESC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count operation groups.
	 *
	 * @return int
	 */
	public static function operation_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT operation_id) FROM ' . self::table_name() . " WHERE operation_id <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Fetch recent field-level rows.
	 *
	 * @param int $limit Row limit.
	 * @return array
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;
		$limit = min( 100, max( 1, absint( $limit ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d', $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return the most recent price change.
	 *
	 * @return array|null
	 */
	public static function latest() {
		$rows = self::recent( 1 );
		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * Roll an operation back only when current values still equal its new values.
	 *
	 * @param string $operation_id Operation identifier.
	 * @return array|WP_Error
	 */
	public static function rollback_operation( $operation_id ) {
		global $wpdb;

		$operation_id = sanitize_key( $operation_id );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE operation_id = %s AND rolled_back_at IS NULL ORDER BY id DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$operation_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return new WP_Error( 'wem_nothing_to_rollback', 'این عملیات قبلاً بازگردانی شده یا تاریخچه‌ای برای آن وجود ندارد.' );
		}
		if ( 'rollback' === $rows[0]['change_type'] ) {
			return new WP_Error( 'wem_rollback_of_rollback', 'عملیات بازگردانی دوباره قابل بازگردانی نیست.' );
		}

		$groups = array();
		foreach ( $rows as $row ) {
			$object_id              = ! empty( $row['variation_id'] ) ? absint( $row['variation_id'] ) : absint( $row['product_id'] );
			$groups[ $object_id ][] = $row;
		}

		$result = array(
			'updated'               => 0,
			'skipped'               => 0,
			'errors'                => array(),
			'rollback_operation_id' => self::new_operation_id( 'rollback' ),
		);
		$parents = array();

		foreach ( $groups as $object_id => $product_rows ) {
			try {
				$product = wc_get_product( $object_id );
				if ( ! $product || ! current_user_can( 'edit_post', $object_id ) ) {
					throw new RuntimeException( 'محصول پیدا نشد یا اجازه ویرایش ندارید.' );
				}

				$changes = array();
				foreach ( $product_rows as $row ) {
					$field   = 'sale_price' === $row['price_field'] ? 'sale_price' : 'regular_price';
					$current = 'sale_price' === $field ? (string) $product->get_sale_price( 'edit' ) : (string) $product->get_regular_price( 'edit' );
					if ( ! self::decimal_equal( $current, $row['new_price'] ) ) {
						throw new RuntimeException( 'قیمت پس از این عملیات دوباره تغییر کرده است؛ بازگردانی خودکار برای جلوگیری از بازنویسی رد شد.' );
					}
					$changes[ $field ] = array( 'old' => $current, 'new' => $row['old_price'], 'log_id' => absint( $row['id'] ) );
				}

				foreach ( $changes as $field => $change ) {
					if ( 'sale_price' === $field ) {
						$product->set_sale_price( $change['new'] );
					} else {
						$product->set_regular_price( $change['new'] );
					}
				}
				$product->save();
				wc_delete_product_transients( $object_id );

				foreach ( $changes as $field => $change ) {
					$wpdb->update(
						self::table_name(),
						array(
							'rolled_back_at' => current_time( 'mysql' ),
							'rolled_back_by' => get_current_user_id(),
						),
						array( 'id' => $change['log_id'] ),
						array( '%s', '%d' ),
						array( '%d' )
					);
				}

				$logged = self::record_changes(
					$product,
					$changes,
					array(
						'operation_id'    => $result['rollback_operation_id'],
						'operation_label' => 'بازگردانی عملیات ' . $operation_id,
						'change_type'     => 'rollback',
						'context'         => array( 'rollback_of' => $operation_id ),
					)
				);
				if ( is_wp_error( $logged ) ) {
					$result['errors'][] = 'ID ' . $object_id . ': بازگردانی انجام شد اما ثبت تاریخچه خطا داشت: ' . $logged->get_error_message();
				}

				if ( $product->get_parent_id() ) {
					$parents[ $product->get_parent_id() ] = true;
				}
				++$result['updated'];
			} catch ( Throwable $e ) {
				++$result['skipped'];
				$result['errors'][] = 'ID ' . $object_id . ': ' . $e->getMessage();
			}
		}

		foreach ( array_keys( $parents ) as $parent_id ) {
			try {
				WC_Product_Variable::sync( $parent_id );
				WC_Product_Variable::sync_stock_status( $parent_id );
				wc_delete_product_transients( $parent_id );
			} catch ( Throwable $e ) {
				$result['errors'][] = 'ID والد ' . $parent_id . ': همگام‌سازی تنوع‌ها کامل نشد: ' . $e->getMessage();
			}
		}

		return $result;
	}

	/**
	 * Normalize a decimal while retaining an intentionally empty sale price.
	 *
	 * @param mixed $value Decimal value.
	 * @return string
	 */
	private static function canonical_decimal( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false !== strpos( $value, '.' ) ) {
			$value = rtrim( rtrim( $value, '0' ), '.' );
		}
		$value = ltrim( $value, '0' );
		return '' === $value ? '0' : ( 0 === strpos( $value, '.' ) ? '0' . $value : $value );
	}

	/**
	 * Compare decimal strings.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	private static function decimal_equal( $a, $b ) {
		return self::canonical_decimal( $a ) === self::canonical_decimal( $b );
	}
}
