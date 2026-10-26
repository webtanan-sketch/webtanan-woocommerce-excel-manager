<?php
/**
 * Round-trip checks for the real XLSX writer and reader.
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_tempnam( $filename = '' ) {
	return tempnam( sys_get_temp_dir(), 'wem-xlsx-' );
}

require_once dirname( __DIR__ ) . '/includes/class-wem-xlsx.php';

$missing = WEM_XLSX::missing_requirements();
if ( $missing ) {
	fwrite( STDERR, 'XLSX writer test requires: ' . implode( ', ', $missing ) . PHP_EOL );
	exit( 1 );
}

$output_path = tempnam( sys_get_temp_dir(), 'wem-export-' );
if ( false === $output_path ) {
	fwrite( STDERR, 'Unable to create a temporary XLSX test file.' . PHP_EOL );
	exit( 1 );
}

$expected = array(
	array( 'ID', 'شناسه محصول', 'عنوان', 'وضعیت محصول', 'قیمت عادی', 'قیمت حراج', 'موجودی', 'دسته‌بندی', 'نوع محصول', 'ID والد', 'مشخصات تنوع' ),
	array( '10', '00803003', 'کالای & ابزار فارسی', 'منتشر شده', '125000', '99000', '7', 'دسته آزمایشی', 'ساده', '', '=SUM(1,1)' ),
);
$failure = '';

try {
	WEM_XLSX::write( $output_path, $expected );

	$actual = array();
	$count  = WEM_XLSX::read_rows(
		$output_path,
		function ( $row ) use ( &$actual ) {
			$actual[] = $row;
		},
		10
	);

	if ( 2 !== $count || $expected !== $actual ) {
		throw new RuntimeException( 'XLSX round-trip mismatch: ' . var_export( $actual, true ) );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $output_path ) ) {
		throw new RuntimeException( 'Generated XLSX cannot be reopened as a ZIP archive.' );
	}
	$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip->close();
	$has_formula = false === $sheet ? false : preg_match( '/<f(?:\s|>)/', $sheet );

	if ( false === $sheet || false === $has_formula || 1 === $has_formula ) {
		throw new RuntimeException( 'Generated XLSX is missing its worksheet or contains a formula.' );
	}
	if ( false === strpos( $sheet, 'rightToLeft="1"' ) || false === strpos( $sheet, 'state="frozen"' ) ) {
		throw new RuntimeException( 'Generated XLSX is missing its RTL view or frozen header row.' );
	}

	echo "XLSX writer and reader round-trip passed.\n";
} catch ( Throwable $e ) {
	$failure = 'XLSX writer test failed: ' . $e->getMessage();
} finally {
	if ( is_file( $output_path ) ) {
		@unlink( $output_path );
	}
}

if ( $failure ) {
	fwrite( STDERR, $failure . PHP_EOL );
	exit( 1 );
}
