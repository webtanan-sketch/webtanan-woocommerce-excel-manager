<?php
/**
 * Price rule engine foundation for future advanced pricing rules.
 *
 * This class is intentionally isolated from admin rendering so new rules
 * (attributes, categories, selected products) can be added without growing
 * the existing admin controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Rule_Engine {

	/**
	 * Supported operations.
	 */
	const OPERATIONS = array(
		'increase_fixed',
		'decrease_fixed',
		'increase_percent',
		'decrease_percent',
	);

	/**
	 * Apply a rule calculation without saving product data.
	 * Used by preview screens.
	 *
	 * @param float|int $price Current price.
	 * @param array     $rule  Rule configuration.
	 * @return string
	 */
	public static function calculate( $price, array $rule ) {
		$current = (float) $price;
		$value   = isset( $rule['value'] ) ? (float) $rule['value'] : 0;
		$type    = isset( $rule['operation'] ) ? $rule['operation'] : '';

		switch ( $type ) {
			case 'increase_fixed':
				$current += $value;
				break;
			case 'decrease_fixed':
				$current -= $value;
				break;
			case 'increase_percent':
				$current *= ( 1 + ( $value / 100 ) );
				break;
			case 'decrease_percent':
				$current *= ( 1 - ( $value / 100 ) );
				break;
		}

		return (string) max( 0, round( $current ) );
	}

	/**
	 * Check a product against a future rule condition.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $rule Rule.
	 * @return bool
	 */
	public static function matches( $product, array $rule ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( isset( $rule['attribute'] ) && isset( $rule['value'] ) ) {
			$attributes = $product->get_attributes();
			$name       = sanitize_title( $rule['attribute'] );

			foreach ( $attributes as $attribute_name => $attribute ) {
				if ( sanitize_title( $attribute_name ) === $name ) {
					return in_array( $rule['value'], $attribute->get_options(), true );
				}
			}
		}

		return true;
	}
}
