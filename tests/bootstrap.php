<?php
/**
 * Smoke-test plugin bootstrap without a WordPress installation.
 */

define( 'ABSPATH', __DIR__ . '/' );

function plugin_dir_path( $file ) {
	return dirname( $file ) . DIRECTORY_SEPARATOR;
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['wem_activation_callback'] = $callback;
}

function add_action( $hook, $callback ) {
	$GLOBALS['wem_actions'][ $hook ][] = $callback;
}

require_once dirname( __DIR__ ) . '/webtanan-woocommerce-excel-manager.php';

$classes = array(
	'WEM_XLSX',
	'WEM_Plugin',
	'WEM_Price_Tools',
	'WEM_Price_Rule_Engine',
	'WEM_Price_Rule_Repository',
	'WEM_Price_Log',
	'WEM_Installer',
	'WEM_Admin_Menu',
	'WEM_Price_Rules_Page',
	'WEM_History_Page',
);

foreach ( $classes as $class ) {
	if ( ! class_exists( $class, false ) ) {
		fwrite( STDERR, 'Bootstrap failed: missing class ' . $class . PHP_EOL );
		exit( 1 );
	}
}

if ( '2.1.0' !== WEM_VERSION ) {
	fwrite( STDERR, 'Bootstrap failed: unexpected plugin version.' . PHP_EOL );
	exit( 1 );
}

if ( empty( $GLOBALS['wem_activation_callback'] ) || empty( $GLOBALS['wem_actions']['plugins_loaded'] ) ) {
	fwrite( STDERR, 'Bootstrap failed: activation or plugins_loaded hook was not registered.' . PHP_EOL );
	exit( 1 );
}

if ( ! is_file( dirname( __DIR__ ) . '/assets/css/admin.css' ) || ! is_file( dirname( __DIR__ ) . '/assets/js/admin.js' ) ) {
	fwrite( STDERR, 'Bootstrap failed: admin assets are missing.' . PHP_EOL );
	exit( 1 );
}

echo "Plugin bootstrap smoke test passed.\n";
