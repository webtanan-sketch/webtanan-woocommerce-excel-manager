<?php
/**
 * Exercise the actual export response in a child process, including output buffers.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

if ( isset( $argv[1] ) && '--fixture' === $argv[1] ) {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WEM_DOWNLOAD_TEST_DIR', $argv[3] );
	class WooCommerce {}
	function current_user_can( $capability ) { return true; }
	function check_admin_referer( $action ) {}
	function wc_get_product( $id ) { return false; }
	function wc_get_products( $args ) { return (object) array( 'products' => array(), 'max_num_pages' => 1 ); }
	function apply_filters( $hook, $value ) { return $value; }
	function wp_tempnam( $filename = '' ) { return tempnam( WEM_DOWNLOAD_TEST_DIR, 'wem-' ); }
	function nocache_headers() {}
	function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
	function wp_die( $message ) { fwrite( STDERR, $message . PHP_EOL ); exit( 1 ); }

	require_once dirname( __DIR__ ) . '/includes/class-wem-xlsx.php';
	require_once dirname( __DIR__ ) . '/includes/class-wem-plugin.php';

	if ( 'discard' === $argv[2] ) {
		ob_start( static function ( $output ) { return ''; } );
	} elseif ( 'prepend' === $argv[2] ) {
		ob_start();
		echo "Output from another plugin\n";
		ob_start();
		echo "Nested output buffer\n";
	} elseif ( 'locked' === $argv[2] ) {
		ob_start( null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE );
	}

	WEM_Plugin::handle_export();
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-wem-xlsx.php';

$directory = sys_get_temp_dir() . '/wem-download-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $directory, 0700 ) ) {
	fwrite( STDERR, "Unable to create the download test directory.\n" );
	exit( 1 );
}

$failure = '';
try {
	foreach ( array( 'plain', 'discard', 'prepend', 'locked' ) as $mode ) {
		$pipes   = array();
		$process = proc_open(
			array( PHP_BINARY, __FILE__, '--fixture', $mode, $directory ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Unable to start the export fixture.' );
		}
		fclose( $pipes[0] );
		$download = stream_get_contents( $pipes[1] );
		$errors   = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		if ( 'locked' === $mode ) {
			if ( 1 !== $status || '' !== $download || false === strpos( $errors, 'بافر خروجی سرور قابل پاک‌سازی نیست' ) ) {
				throw new RuntimeException( 'A non-removable buffer did not fail safely before the download.' );
			}
			echo "locked: download blocked with a clear error\n";
			continue;
		}
		if ( 0 !== $status || '' !== $errors || substr( $download, 0, 4 ) !== "PK\x03\x04" ) {
			throw new RuntimeException( sprintf( '%s: invalid or empty download (%d bytes); %s', $mode, strlen( $download ), $errors ) );
		}
		$path = $directory . '/download.xlsx';
		file_put_contents( $path, $download );
		$rows  = array();
		$count = WEM_XLSX::read_rows( $path, static function ( $row ) use ( &$rows ) { $rows[] = $row; }, 10 );
		if ( 1 !== $count || 11 !== count( $rows[0] ) || 'ID' !== $rows[0][0] ) {
			throw new RuntimeException( $mode . ': downloaded XLSX is not the complete export.' );
		}
		unlink( $path );
		echo $mode . ": valid XLSX download\n";
	}
} catch ( Throwable $e ) {
	$failure = 'Export download test failed: ' . $e->getMessage();
} finally {
	foreach ( glob( $directory . '/*' ) as $path ) {
		unlink( $path );
	}
	rmdir( $directory );
}

if ( $failure ) {
	fwrite( STDERR, $failure . PHP_EOL );
	exit( 1 );
}
