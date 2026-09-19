<?php
/**
 * Regression test for canonicalizing the legacy inline-editor URL.
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function wp_unslash( $value ) {
	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function admin_url( $path ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}

function wp_safe_redirect( $url ) {
	echo $url;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/class-wem-price-tools.php';

$_GET = array(
	'page'             => WEM_Price_Tools::EDITOR_PAGE_SLUG,
	'post_type'        => 'product',
	'category_id'      => '42',
	'include_children' => '1',
	'paged'            => '3',
);

WEM_Price_Tools::redirect_legacy_editor_url();

fwrite( STDERR, "Legacy editor redirect was not triggered.\n" );
exit( 1 );
