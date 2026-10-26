<?php
/** Exercise real repository serialization and grouped audit rollback with lightweight WP doubles. */
require __DIR__ . '/run.php';
define( 'ARRAY_A', 'ARRAY_A' );
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 1; }
function current_user_can( $capability, $id = 0 ) { return true; }
function wp_generate_password( $length, $special = true, $extra = false ) { return str_repeat( 'a', $length ); }
function wc_delete_product_transients( $id ) {}
class WEM_Test_DB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	public $rows = array();
	public $rule;
	public function insert( $table, $row, $formats ) {
		$this->insert_id++;
		$row['id'] = $this->insert_id;
		if ( false !== strpos( $table, 'price_rules' ) ) {
			$this->rule = $row;
		} else {
			$this->rows[] = $row;
		}
		return 1;
	}
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_row( $sql, $type ) { return $this->rule; }
	public function get_results( $sql, $type ) { return $this->rows; }
	public function update( $table, $payload, $where, $formats, $where_formats ) { return 1; }
}
class WC_Product_Variable {
	public static function sync( $id ) {}
	public static function sync_stock_status( $id ) {}
}
require dirname( __DIR__ ) . '/includes/Pricing/class-wem-price-rule-repository.php';
require dirname( __DIR__ ) . '/includes/Audit/class-wem-price-log.php';
$wpdb = new WEM_Test_DB();
$scheduled['is_active'] = true;
$id = WEM_Price_Rule_Repository::save( $scheduled );
$restored = WEM_Price_Rule_Repository::get( $id );
wem_assert_same( $scheduled['sale_from'], $restored['sale_from'], 'Persist start date in rule JSON' );
wem_assert_same( $scheduled['sale_to'], $restored['sale_to'], 'Persist end date in rule JSON' );
wem_assert_same( 'all', $restored['condition_type'], 'Persist all-products scope' );
$wpdb->rule['condition_value'] = '{"values":[10],"include_children":false}';
$legacy = WEM_Price_Rule_Repository::get( $id );
wem_assert_same( '', $legacy['sale_from'], 'Old rule loads with no start' );
wem_assert_same( '', $legacy['sale_to'], 'Old rule loads with no end' );

$product = new WC_Product( 100, 'simple', '100', '90' );
$product->set_date_on_sale_from( time() + 100 );
$product->set_date_on_sale_to( time() + 200 );
$wem_test_products = array( 100 => $product );
$old = WEM_Price_Rule_Engine::snapshot( $product );
$changes = WEM_Price_Rule_Engine::changes_for_product( $product, $scheduled );
WEM_Price_Rule_Engine::apply_changes( $product, $changes );
WEM_Price_Log::record_changes( $product, $changes, array( 'operation_id' => 'sale-test', 'change_type' => 'rule' ) );
wem_assert_same( 3, count( $wpdb->rows ), 'Audit records price and both schedule fields' );
$original_rows = $wpdb->rows;
$result = WEM_Price_Log::rollback_operation( 'sale-test' );
wem_assert_same( 1, $result['updated'], 'Grouped rollback succeeds' );
wem_assert_same( $old, WEM_Price_Rule_Engine::snapshot( $product ), 'Rollback restores previous price AND schedule' );
// Schedule changed by another administrator: reject the entire product group.
$wpdb->rows = $original_rows;
WEM_Price_Rule_Engine::apply_changes( $product, $changes );
$product->set_date_on_sale_to( time() + 500 );
$before = WEM_Price_Rule_Engine::snapshot( $product );
$result = WEM_Price_Log::rollback_operation( 'sale-test' );
wem_assert_same( 1, $result['skipped'], 'Schedule-only edit blocks rollback' );
wem_assert_same( $before, WEM_Price_Rule_Engine::snapshot( $product ), 'Rejected rollback leaves all fields intact' );
// A later regular-price change must not restore a sale above regular price.
$wpdb->rows = $original_rows;
WEM_Price_Rule_Engine::apply_changes( $product, $changes );
$product->set_regular_price( '85' );
$result = WEM_Price_Log::rollback_operation( 'sale-test' );
wem_assert_same( 1, $result['skipped'], 'Invalid restored sale blocked after regular price change' );
require dirname( __DIR__ ) . '/includes/Admin/class-wem-price-rules-page.php';
$_POST = array(
	'condition_type' => 'all', 'action_type' => 'create_sale_percent',
	'amount' => '۲۰', 'rule_name' => 'Test', 'is_active' => '1',
	'sale_from' => $from, 'sale_to' => $to,
);
$parser = new ReflectionMethod( 'WEM_Price_Rules_Page', 'rule_from_request' );
$parser->setAccessible( true );
$parsed = $parser->invoke( null );
wem_assert_same( '20', $parsed['amount'], 'Persian percentage normalized' );
wem_assert_same( 'sale', $parsed['price_target'], 'Server forces sale target even when disabled input omitted' );
wem_assert_same( array(), WEM_Price_Rule_Engine::validate( $parsed ), 'Parsed sale request validates' );
$_POST['condition_type'] = 'product';
$_POST['product_ids'] = '۱۲۳،۴۵۶';
wem_assert_same( array( 123, 456 ), $parser->invoke( null )['condition_values'], 'Persian product IDs normalized' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Bulk sale persistence and rollback tests passed.\n";
