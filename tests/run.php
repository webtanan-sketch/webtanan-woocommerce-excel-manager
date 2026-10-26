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

function wp_timezone() { return new DateTimeZone( 'Asia/Tehran' ); }
function wc_get_price_decimals() { return 2; }
function get_term_children( $id, $taxonomy ) { return 20 === $id ? array( 21 ) : array(); }
function wp_get_post_terms( $id, $taxonomy, $args ) { return 10 === $id ? array( 21 ) : array(); }
function get_terms( $args ) {
	return array_map( function ( $id ) { return (object) array( 'slug' => 'cat-' . $id ); }, array_filter( $args['include'], function ( $id ) { return in_array( $id, array( 20, 21 ), true ); } ) );
}
function wp_list_pluck( $items, $key ) { return array_map( function ( $item ) use ( $key ) { return $item->$key; }, $items ); }
function wc_get_products( $args ) {
	$GLOBALS['last_query'] = $args;
	return array_slice( array_values( $GLOBALS['wem_query_products'] ?? $GLOBALS['wem_test_products'] ), 0, $args['limit'] );
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
	private $sale_from;
	private $sale_to;

	public function __construct( $id, $type, $regular, $sale = '', $parent_id = 0, $sku = '', $attributes = array() ) {
		$this->id         = $id;
		$this->type       = $type;
		$this->regular    = $regular;
		$this->sale       = $sale;
		$this->parent_id  = $parent_id;
		$this->sku        = $sku;
		$this->attributes = $attributes;
	}

	public function get_date_on_sale_from() { return $this->sale_from; }
	public function get_date_on_sale_to() { return $this->sale_to; }
	public function set_date_on_sale_from( $value ) { $this->sale_from = '' === $value ? null : new WEM_Test_Date( $value ); }
	public function set_date_on_sale_to( $value ) { $this->sale_to = '' === $value ? null : new WEM_Test_Date( $value ); }
	public function set_sale_price( $value ) { $this->sale = $value; }
	public function set_regular_price( $value ) { $this->regular = $value; }
	public function get_children() { return array( 11, 12 ); }
	public function save() { return $this->id; }
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

$discount = array_merge( $base_rule, array( 'condition_type' => 'all', 'condition_values' => array(), 'action_type' => 'create_sale_percent', 'price_target' => 'sale', 'amount' => '20', 'sale_from' => '', 'sale_to' => '' ) );
wem_assert_same( array(), WEM_Price_Rule_Engine::validate( $discount ), 'All products need no condition value' );
$fresh = new WC_Product( 15, 'simple', '100' );
$sale = WEM_Price_Rule_Engine::changes_for_product( $fresh, $discount );
wem_assert_same( '80.00', $sale['sale_price']['new'], 'Create sale on a product without a previous sale' );
wem_assert_true( ! isset( $sale['regular_price'] ), 'Regular price unchanged' );
WEM_Price_Rule_Engine::apply_changes( $fresh, $sale );
wem_assert_same( array(), WEM_Price_Rule_Engine::changes_for_product( $fresh, $discount ), 'Repeated sale does not compound' );
wem_assert_same( '80.00', WEM_Price_Rule_Engine::changes_for_product( new WC_Product( 16, 'simple', '100', '60' ), $discount )['sale_price']['new'], 'Existing sale replaced using regular price' );
foreach ( array( '0', '101', '-1', 'bad' ) as $amount ) {
	wem_assert_true( count( WEM_Price_Rule_Engine::validate( array_merge( $discount, array( 'amount' => $amount ) ) ) ) > 0, 'Invalid discount rejected: ' . $amount );
}
$free = WEM_Price_Rule_Engine::changes_for_product( $fresh, array_merge( $discount, array( 'amount' => '100' ) ) );
wem_assert_same( '0.00', $free['sale_price']['new'], 'Explicit 100 percent sale allowed' );
wem_assert_same( array(), WEM_Price_Rule_Engine::changes_for_product( new WC_Product( 17, 'simple', '' ), $discount ), 'Missing regular price skipped' );
wem_assert_same( array(), WEM_Price_Rule_Engine::changes_for_product( new WC_Product( 17, 'simple', '0' ), $discount ), 'Zero regular price skipped' );
wem_assert_true( is_wp_error( WEM_Price_Rule_Engine::changes_for_product( $fresh, array_merge( $discount, array( 'round_to' => 100 ) ) ) ), 'Rounding to regular price rejected' );
$from = ( new DateTimeImmutable( '+2 days', wp_timezone() ) )->format( 'Y-m-d\TH:i' );
$to = ( new DateTimeImmutable( '+3 days', wp_timezone() ) )->format( 'Y-m-d\TH:i' );
$scheduled = array_merge( $discount, array( 'sale_from' => $from, 'sale_to' => $to ) );
$changes = WEM_Price_Rule_Engine::changes_for_product( $fresh, $scheduled );
wem_assert_same( ( new DateTimeImmutable( $from, wp_timezone() ) )->getTimestamp(), (int) $changes['date_on_sale_from']['new'], 'Site timezone converts to UTC instant' );
WEM_Price_Rule_Engine::apply_changes( $fresh, $changes );
$old_snapshot = WEM_Price_Rule_Engine::snapshot( $fresh );
$fresh->set_date_on_sale_to( time() + 9999 );
wem_assert_true( ! WEM_Price_Rule_Engine::snapshot_matches( $old_snapshot, WEM_Price_Rule_Engine::snapshot( $fresh ) ), 'Schedule-only concurrent edit invalidates preview' );
$clear = WEM_Price_Rule_Engine::changes_for_product( $fresh, $discount );
wem_assert_same( '', $clear['date_on_sale_from']['new'], 'Immediate sale clears old start' );
wem_assert_same( '', $clear['date_on_sale_to']['new'], 'Open-ended sale clears old end' );
foreach ( array(
	array( 'sale_from' => '2030-02-30T12:00' ),
	array( 'sale_to' => '2020-01-01T12:00' ),
	array( 'sale_from' => $to, 'sale_to' => $from ),
	array( 'sale_to' => 'garbage' ),
) as $invalid ) {
	wem_assert_true( count( WEM_Price_Rule_Engine::validate( array_merge( $discount, $invalid ) ) ) > 0, 'Invalid sale dates rejected' );
}
$parent = new WC_Product( 10, 'variable', '' );
$child1 = new WC_Product( 11, 'variation', '200', '', 10 );
$child2 = new WC_Product( 12, 'variation', '300', '', 10 );
$wem_test_products = array( 10 => $parent, 11 => $child1, 12 => $child2 );
wem_assert_same( 2, count( WEM_Price_Rule_Engine::matching_products( $discount ) ), 'All products expands and deduplicates variations' );
wem_assert_same( '160.00', WEM_Price_Rule_Engine::changes_for_product( $child1, $discount )['sale_price']['new'], 'Variation own regular price is used' );
$category = array_merge( $discount, array( 'condition_type' => 'category', 'condition_values' => array( 20 ), 'include_children' => true ) );
wem_assert_true( WEM_Price_Rule_Engine::matches( $child1, $category ), 'Category inherited from parent including descendants' );
$category['include_children'] = false;
wem_assert_true( ! WEM_Price_Rule_Engine::matches( $child1, $category ), 'Category descendants optional' );
$category['condition_values'] = array( 999 );
wem_assert_same( array(), WEM_Price_Rule_Engine::matching_products( $category ), 'Deleted category never falls back to whole catalog' );
$wem_query_products = array( 10 => $parent );
try {
	WEM_Price_Rule_Engine::matching_products( $discount, 1 );
	wem_assert_true( false, 'Oversized variation set must fail' );
} catch ( RuntimeException $e ) {
	wem_assert_true( true, 'Oversized variation set fails safely' );
}

if ( $failures ) {
	fwrite( STDERR, "Pricing engine tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Pricing engine tests passed (including bulk sales and schedules).\n";
