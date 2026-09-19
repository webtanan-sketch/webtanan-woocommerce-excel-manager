<?php
/**
 * Product matching and price calculation for saved rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Rule_Engine {
	const MAX_MATCHES = 10000;

	/**
	 * Validate a normalized rule.
	 *
	 * @param array $rule Rule data.
	 * @return array Error messages.
	 */
	public static function validate( array $rule ) {
		$errors     = array();
		$conditions = array( 'attribute', 'category', 'product', 'sku' );
		$actions    = array( 'increase_fixed', 'decrease_fixed', 'increase_percent', 'decrease_percent' );
		$targets    = array( 'regular', 'sale', 'both' );
		$rounding   = array( 1, 10, 100, 1000, 10000 );

		if ( empty( $rule['name'] ) ) {
			$errors[] = 'نام قانون الزامی است.';
		}
		if ( empty( $rule['condition_type'] ) || ! in_array( $rule['condition_type'], $conditions, true ) ) {
			$errors[] = 'نوع شرط معتبر نیست.';
		}
		if ( empty( $rule['condition_values'] ) ) {
			$errors[] = 'حداقل یک مقدار برای شرط وارد کنید.';
		}
		if ( 'attribute' === $rule['condition_type'] && ( empty( $rule['condition_key'] ) || ! taxonomy_exists( $rule['condition_key'] ) ) ) {
			$errors[] = 'ویژگی سراسری ووکامرس معتبر نیست.';
		}
		if ( empty( $rule['action_type'] ) || ! in_array( $rule['action_type'], $actions, true ) ) {
			$errors[] = 'نوع تغییر قیمت معتبر نیست.';
		}
		if ( ! isset( $rule['amount'] ) || ! is_numeric( $rule['amount'] ) || (float) $rule['amount'] <= 0 ) {
			$errors[] = 'مقدار تغییر باید عددی بیشتر از صفر باشد.';
		}
		if ( false !== strpos( (string) $rule['action_type'], 'percent' ) && (float) $rule['amount'] > 1000 ) {
			$errors[] = 'درصد تغییر نمی‌تواند بیشتر از ۱۰۰۰ باشد.';
		}
		if ( empty( $rule['price_target'] ) || ! in_array( $rule['price_target'], $targets, true ) ) {
			$errors[] = 'قیمت هدف معتبر نیست.';
		}
		if ( ! in_array( absint( $rule['round_to'] ), $rounding, true ) ) {
			$errors[] = 'روش گرد کردن معتبر نیست.';
		}

		return $errors;
	}

	/**
	 * Calculate a price using a rule.
	 *
	 * @param string|float $price Current price.
	 * @param array        $rule  Rule data.
	 * @return string
	 */
	public static function calculate( $price, array $rule ) {
		if ( '' === trim( (string) $price ) || ! is_numeric( $price ) ) {
			return '';
		}

		$value  = (float) $price;
		$type   = isset( $rule['action_type'] ) ? sanitize_key( $rule['action_type'] ) : sanitize_key( isset( $rule['type'] ) ? $rule['type'] : '' );
		$amount = isset( $rule['amount'] ) ? (float) $rule['amount'] : 0;

		switch ( $type ) {
			case 'increase_fixed':
				$value += $amount;
				break;
			case 'decrease_fixed':
				$value -= $amount;
				break;
			case 'increase_percent':
				$value *= 1 + ( $amount / 100 );
				break;
			case 'decrease_percent':
				$value *= 1 - ( $amount / 100 );
				break;
			default:
				return wc_format_decimal( $price );
		}

		$value    = max( 0, $value );
		$round_to = isset( $rule['round_to'] ) ? absint( $rule['round_to'] ) : 1;
		if ( $round_to > 1 ) {
			$value = round( $value / $round_to ) * $round_to;
		} else {
			$value = round( $value, 8 );
		}

		return self::canonical_decimal( wc_format_decimal( $value, 8 ) );
	}

	/**
	 * Return non-variable products that match a rule.
	 *
	 * @param array $rule  Rule data.
	 * @param int   $limit Safety limit.
	 * @return array
	 */
	public static function matching_products( array $rule, $limit = self::MAX_MATCHES ) {
		$limit      = min( self::MAX_MATCHES, max( 1, absint( $limit ) ) );
		$candidates = self::candidate_products( $rule, $limit + 1 );
		$matches    = array();
		$seen       = array();

		foreach ( $candidates as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			foreach ( self::expand_product( $product ) as $candidate ) {
				$id = $candidate->get_id();
				if ( isset( $seen[ $id ] ) || $candidate->is_type( 'variable' ) || ! self::matches( $candidate, $rule ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$matches[]   = $candidate;
				if ( count( $matches ) > $limit ) {
					throw new RuntimeException( 'تعداد محصولات منطبق از حد ایمن ۱۰٬۰۰۰ مورد بیشتر است؛ شرط دقیق‌تری انتخاب کنید.' );
				}
			}
		}

		usort(
			$matches,
			function ( $a, $b ) {
				return $a->get_id() - $b->get_id();
			}
		);

		return $matches;
	}

	/**
	 * Determine whether a product matches a rule.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $rule    Rule data.
	 * @return bool
	 */
	public static function matches( $product, array $rule ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$type   = isset( $rule['condition_type'] ) ? sanitize_key( $rule['condition_type'] ) : '';
		$values = array_values( array_filter( array_map( 'strval', isset( $rule['condition_values'] ) ? (array) $rule['condition_values'] : array() ), 'strlen' ) );

		switch ( $type ) {
			case 'attribute':
				foreach ( $values as $value ) {
					if ( self::matches_attribute( $product, $rule['condition_key'], $value ) ) {
						return true;
					}
				}
				return false;
			case 'category':
				return self::matches_category( $product, array_map( 'absint', $values ), ! empty( $rule['include_children'] ) );
			case 'product':
				$ids = array_map( 'absint', $values );
				return in_array( $product->get_id(), $ids, true ) || ( $product->get_parent_id() && in_array( $product->get_parent_id(), $ids, true ) );
			case 'sku':
				$skus = array_map( 'strtolower', $values );
				if ( in_array( strtolower( (string) $product->get_sku( 'edit' ) ), $skus, true ) ) {
					return true;
				}
				$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : false;
				return $parent && in_array( strtolower( (string) $parent->get_sku( 'edit' ) ), $skus, true );
		}

		return false;
	}

	/**
	 * Check a global WooCommerce attribute on simple products and variations.
	 *
	 * @param WC_Product $product   Product object.
	 * @param string     $attribute Attribute taxonomy.
	 * @param string     $value     Term slug or name.
	 * @return bool
	 */
	public static function matches_attribute( $product, $attribute, $value ) {
		if ( ! $product instanceof WC_Product || ! $attribute ) {
			return false;
		}

		$attribute = sanitize_key( str_replace( 'attribute_', '', $attribute ) );
		$needle    = self::normalize_match_value( $value );
		$object    = $product;

		if ( $product->is_type( 'variation' ) ) {
			$attributes = $product->get_attributes();
			if ( isset( $attributes[ $attribute ] ) ) {
				$variation_value = (string) $attributes[ $attribute ];
				if ( $needle === self::normalize_match_value( $variation_value ) ) {
					return true;
				}
				$term = get_term_by( 'slug', $variation_value, $attribute );
				if ( $term && $needle === self::normalize_match_value( $term->name ) ) {
					return true;
				}
			}
			$object = wc_get_product( $product->get_parent_id() );
		}

		if ( ! $object ) {
			return false;
		}

		$terms = wc_get_product_terms( $object->get_id(), $attribute, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( $needle === self::normalize_match_value( $term->slug ) || $needle === self::normalize_match_value( $term->name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create a concurrency snapshot for previews.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	public static function snapshot( $product ) {
		$modified = $product->get_date_modified( 'edit' );
		return array(
			'regular_price' => (string) $product->get_regular_price( 'edit' ),
			'sale_price'    => (string) $product->get_sale_price( 'edit' ),
			'modified_ts'   => $modified ? (int) $modified->getTimestamp() : 0,
		);
	}

	/**
	 * Compare a current product snapshot to its preview snapshot.
	 *
	 * @param array $current  Current snapshot.
	 * @param array $expected Preview snapshot.
	 * @return bool
	 */
	public static function snapshot_matches( array $current, array $expected ) {
		return isset( $current['modified_ts'], $expected['modified_ts'] )
			&& (int) $current['modified_ts'] === (int) $expected['modified_ts']
			&& self::decimal_equal( $current['regular_price'], $expected['regular_price'] )
			&& self::decimal_equal( $current['sale_price'], $expected['sale_price'] );
	}

	/**
	 * Build field-level changes while enforcing WooCommerce sale-price rules.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $rule    Rule data.
	 * @return array|WP_Error
	 */
	public static function changes_for_product( $product, array $rule ) {
		$old_regular = (string) $product->get_regular_price( 'edit' );
		$old_sale    = (string) $product->get_sale_price( 'edit' );
		$new_regular = $old_regular;
		$new_sale    = $old_sale;
		$changes     = array();
		$target      = isset( $rule['price_target'] ) ? $rule['price_target'] : 'regular';

		if ( in_array( $target, array( 'regular', 'both' ), true ) && '' !== $old_regular ) {
			$new_regular = self::calculate( $old_regular, $rule );
			if ( ! self::decimal_equal( $new_regular, $old_regular ) ) {
				$changes['regular_price'] = array( 'old' => $old_regular, 'new' => $new_regular );
			}
		}
		if ( in_array( $target, array( 'sale', 'both' ), true ) && '' !== $old_sale ) {
			$new_sale = self::calculate( $old_sale, $rule );
			if ( ! self::decimal_equal( $new_sale, $old_sale ) ) {
				$changes['sale_price'] = array( 'old' => $old_sale, 'new' => $new_sale );
			}
		}

		if ( '' !== $new_sale && '' !== $new_regular && (float) $new_sale >= (float) $new_regular ) {
			return new WP_Error( 'wem_invalid_sale_price', 'قیمت حراج پس از اجرای قانون باید کمتر از قیمت عادی باشد.' );
		}

		return $changes;
	}

	/**
	 * Query the smallest practical candidate set for a rule.
	 *
	 * @param array $rule  Rule data.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private static function candidate_products( array $rule, $limit ) {
		$type   = $rule['condition_type'];
		$values = isset( $rule['condition_values'] ) ? (array) $rule['condition_values'] : array();
		$result = array();

		if ( 'product' === $type ) {
			foreach ( array_slice( array_unique( array_map( 'absint', $values ) ), 0, $limit ) as $id ) {
				$product = $id ? wc_get_product( $id ) : false;
				if ( $product ) {
					$result[] = $product;
				}
			}
			return $result;
		}

		if ( 'sku' === $type ) {
			foreach ( array_slice( array_unique( array_map( 'strval', $values ) ), 0, $limit ) as $sku ) {
				$id      = wc_get_product_id_by_sku( $sku );
				$product = $id ? wc_get_product( $id ) : false;
				if ( $product ) {
					$result[] = $product;
				}
			}
			return $result;
		}

		$args = array(
			'limit'   => $limit,
			'status'  => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		if ( 'category' === $type ) {
			$term_ids = array_unique( array_filter( array_map( 'absint', $values ) ) );
			if ( ! empty( $rule['include_children'] ) ) {
				foreach ( $term_ids as $term_id ) {
					$children = get_term_children( $term_id, 'product_cat' );
					if ( ! is_wp_error( $children ) ) {
						$term_ids = array_merge( $term_ids, array_map( 'absint', $children ) );
					}
				}
			}
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'include' => array_unique( $term_ids ), 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exception text is not rendered here; the admin handler escapes it.
				throw new RuntimeException( $terms->get_error_message() );
			}
			$args['category'] = wp_list_pluck( $terms, 'slug' );
		}

		return wc_get_products( $args );
	}

	/**
	 * Expand a variable parent into price-bearing variations.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	private static function expand_product( $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return array( $product );
		}

		$result = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation ) {
				$result[] = $variation;
			}
		}
		return $result;
	}

	/**
	 * Check product category membership.
	 *
	 * @param WC_Product $product          Product object.
	 * @param array      $category_ids     Category IDs.
	 * @param bool       $include_children Include descendants.
	 * @return bool
	 */
	private static function matches_category( $product, array $category_ids, $include_children ) {
		$ids = array_unique( array_filter( array_map( 'absint', $category_ids ) ) );
		if ( $include_children ) {
			foreach ( $ids as $category_id ) {
				$children = get_term_children( $category_id, 'product_cat' );
				if ( ! is_wp_error( $children ) ) {
					$ids = array_merge( $ids, array_map( 'absint', $children ) );
				}
			}
		}

		$object_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$assigned  = wp_get_post_terms( $object_id, 'product_cat', array( 'fields' => 'ids' ) );
		return ! is_wp_error( $assigned ) && (bool) array_intersect( array_unique( $ids ), array_map( 'absint', $assigned ) );
	}

	/**
	 * Normalize term matching across case and Persian whitespace.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function normalize_match_value( $value ) {
		$value = str_replace( array( 'ي', 'ك', '‌' ), array( 'ی', 'ک', ' ' ), trim( (string) $value ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Normalize a decimal for reliable comparisons.
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
