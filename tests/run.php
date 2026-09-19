<?php
/**
 * Dependency-free smoke tests for the pure pricing engine behavior.
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wc_format_decimal( $value, $decimals = 8 ) {
	return number_format( (float) $value, $decimals, '.', '' );
}

function taxonomy_exists( $taxonomy ) {
	return 'pa_size' === $taxonomy;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wc_get_product_terms( $product_id, $taxonomy, $args ) {
	if ( 10 === $product_id && 'pa_size' === $taxonomy ) {
		return array( (object) array( 'slug' => '60x120', 'name' => '۶۰×۱۲۰' ) );
	}
	return array();
}

function get_term_by( $field, $value, $taxonomy ) {
	if ( 'slug' === $field && '60x120' === $value && 'pa_size' === $taxonomy ) {
		return (object) array( 'slug' => '60x120', 'name' => '۶۰×۱۲۰' );
	}
	return false;
}

function wc_get_product( $product_id ) {
	global $wem_test_products;
	return isset( $wem_test_products[ $product_id ] ) ? $wem_test_products[ $product_id ] : false;
}

class WP_Error {
	private $message;

	public function __construct( $code, $message ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class WEM_Test_Date {
	private $timestamp;

	public function __construct( $timestamp ) {
		$this->timestamp = $timestamp;
	}

	public function getTimestamp() {
		return $this->timestamp;
	}
}

class WC_Product {
	private $id;
	private $parent_id;
	private $type;
	private $regular;
	private $sale;
	private $sku;
	private $attributes;

	public function __construct( $id, $type, $regular, $sale = '', $parent_id = 0, $sku = '', $attributes = array() ) {
		$this->id         = $id;
		$this->type       = $type;
		$this->regular    = $regular;
		$this->sale       = $sale;
		$this->parent_id  = $parent_id;
		$this->sku        = $sku;
		$this->attributes = $attributes;
	}

	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent_id; }
	public function is_type( $type ) { return $this->type === $type; }
	public function get_regular_price() { return $this->regular; }
	public function get_sale_price() { return $this->sale; }
	public function get_sku() { return $this->sku; }
	public function get_attributes() { return $this->attributes; }
	public function get_date_modified() { return new WEM_Test_Date( 1700000000 ); }
}

require_once dirname( __DIR__ ) . '/includes/Pricing/class-wem-price-rule-engine.php';

$failures = array();

function wem_assert_same( $expected, $actual, $message ) {
	global $failures;
	if ( $expected !== $actual ) {
		$failures[] = $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')';
	}
}

function wem_assert_true( $actual, $message ) {
	wem_assert_same( true, (bool) $actual, $message );
}

$base_rule = array(
	'name'             => 'Test',
	'condition_type'   => 'product',
	'condition_key'    => '',
	'condition_values' => array( 10 ),
	'include_children' => false,
	'action_type'      => 'increase_fixed',
	'amount'           => '50',
	'price_target'     => 'regular',
	'round_to'         => 1,
);

wem_assert_same( '150', WEM_Price_Rule_Engine::calculate( '100', $base_rule ), 'Fixed increase' );

$rule                = $base_rule;
$rule['action_type'] = 'decrease_percent';
$rule['amount']      = '10';
wem_assert_same( '900', WEM_Price_Rule_Engine::calculate( '1000', $rule ), 'Percent decrease' );

$rule                = $base_rule;
$rule['round_to']    = 100;
wem_assert_same( '200', WEM_Price_Rule_Engine::calculate( '123', $rule ), 'Configured rounding' );

$rule                = $base_rule;
$rule['action_type'] = 'decrease_fixed';
$rule['amount']      = '100';
wem_assert_same( '0', WEM_Price_Rule_Engine::calculate( '50', $rule ), 'Negative prices clamp to zero' );
wem_assert_same( '', WEM_Price_Rule_Engine::calculate( '', $rule ), 'Blank prices stay blank' );

$simple = new WC_Product( 10, 'simple', '100', '80', 0, 'SIMPLE-10' );
wem_assert_true( WEM_Price_Rule_Engine::matches( $simple, $base_rule ), 'Direct product ID condition' );

$wem_test_products = array( 10 => $simple );
$variation         = new WC_Product( 11, 'variation', '100', '80', 10, 'VAR-11', array( 'pa_size' => '60x120' ) );
$product_rule      = $base_rule;
wem_assert_true( WEM_Price_Rule_Engine::matches( $variation, $product_rule ), 'Parent product ID includes variations' );
wem_assert_true( WEM_Price_Rule_Engine::matches_attribute( $variation, 'pa_size', '۶۰×۱۲۰' ), 'Variation attribute matches localized term name' );
wem_assert_true( WEM_Price_Rule_Engine::matches_attribute( $simple, 'pa_size', '60x120' ), 'Simple product attribute term matches slug' );

$sale_rule                 = $base_rule;
$sale_rule['price_target'] = 'sale';
$sale_rule['amount']       = '30';
$invalid_changes           = WEM_Price_Rule_Engine::changes_for_product( $simple, $sale_rule );
wem_assert_true( is_wp_error( $invalid_changes ), 'Sale price cannot meet or exceed regular price' );

$both_rule                 = $base_rule;
$both_rule['price_target'] = 'both';
$both_rule['action_type']  = 'increase_percent';
$both_rule['amount']       = '10';
$changes                   = WEM_Price_Rule_Engine::changes_for_product( $simple, $both_rule );
wem_assert_same( '110', $changes['regular_price']['new'], 'Both target updates regular price' );
wem_assert_same( '88', $changes['sale_price']['new'], 'Both target updates sale price' );

$snapshot = WEM_Price_Rule_Engine::snapshot( $simple );
wem_assert_true( WEM_Price_Rule_Engine::snapshot_matches( $snapshot, $snapshot ), 'Identical concurrency snapshots match' );

if ( $failures ) {
	fwrite( STDERR, "Pricing engine tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Pricing engine tests passed (13 assertions).\n";
