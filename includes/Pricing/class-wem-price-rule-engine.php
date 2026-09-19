<?php
/**
 * Pricing rule engine foundation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Rule_Engine {

	/**
	 * Apply a calculation without saving the product.
	 * This is used for preview and future rule based updates.
	 */
	public static function calculate( $price, array $rule ) {
		$value = (float) $price;

		$type = isset( $rule['type'] ) ? sanitize_key( $rule['type'] ) : '';
		$amount = isset( $rule['amount'] ) ? (float) $rule['amount'] : 0;

		switch ( $type ) {
			case 'increase_fixed':
				$value += $amount;
				break;
			case 'decrease_fixed':
				$value -= $amount;
				break;
			case 'increase_percent':
				$value *= ( 1 + ( $amount / 100 ) );
				break;
			case 'decrease_percent':
				$value *= ( 1 - ( $amount / 100 ) );
				break;
		}

		return max( 0, wc_format_decimal( $value ) );
	}

	/**
	 * Check attribute condition.
	 */
	public static function matches_attribute( $product, $attribute, $value ) {
		if ( ! $product || ! $attribute ) {
			return false;
		}

		$attributes = $product->get_attributes();

		foreach ( $attributes as $key => $item ) {
			if ( $key === $attribute || 'pa_' . $key === $attribute ) {
				$values = wc_get_product_terms( $product->get_id(), $key, array( 'fields' => 'names' ) );
				return in_array( $value, $values, true );
			}
		}

		return false;
	}
}
