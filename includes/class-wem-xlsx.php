<?php
/**
 * Lightweight XLSX reader/writer used only for the fixed product sheet.
 * No formulas, macros, embedded objects, or external links are accepted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_XLSX {
	const MAX_ARCHIVE_ENTRIES     = 2000;
	const MAX_UNCOMPRESSED_BYTES = 104857600; // 100 MB.
	const MAX_SINGLE_ENTRY_BYTES = 31457280;  // 30 MB.

	/**
	 * Check required PHP extensions.
	 *
	 * @return array<int,string>
	 */
	public static function missing_requirements() {
		$missing = array();

		if ( ! class_exists( 'ZipArchive' ) ) {
			$missing[] = 'ZipArchive (php-zip)';
		}
		if ( ! class_exists( 'XMLReader' ) ) {
			$missing[] = 'XMLReader (php-xml)';
		}

		return $missing;
	}

	/**
	 * Write a real XLSX workbook.
	 *
	 * @param string   $target_path Output path.
	 * @param iterable $rows        Rows, including the header row.
	 * @throws RuntimeException On failure.
	 */
	public static function write( $target_path, $rows ) {
		$missing = self::missing_requirements();
		if ( ! empty( $missing ) ) {
			throw new RuntimeException( 'Missing PHP extensions: ' . implode( ', ', $missing ) );
		}

		$sheet_path = wp_tempnam( 'wem-sheet.xml' );
		if ( ! $sheet_path ) {
			throw new RuntimeException( 'Unable to create a temporary worksheet.' );
		}

		$handle = fopen( $sheet_path, 'wb' );
		if ( ! $handle ) {
			@unlink( $sheet_path );
			throw new RuntimeException( 'Unable to open the temporary worksheet.' );
		}

		$row_number = 0;

		try {
			self::write_raw( $handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' );
			self::write_raw( $handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' );
			self::write_raw( $handle, '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' );
			self::write_raw( $handle, '<sheetFormatPr defaultRowHeight="15"/>' );
			self::write_raw( $handle, '<cols><col min="1" max="1" width="11" customWidth="1"/><col min="2" max="2" width="22" customWidth="1"/><col min="3" max="3" width="48" customWidth="1"/><col min="4" max="4" width="18" customWidth="1"/><col min="5" max="6" width="18" customWidth="1"/><col min="7" max="7" width="14" customWidth="1"/><col min="8" max="8" width="32" customWidth="1"/><col min="9" max="9" width="20" customWidth="1"/><col min="10" max="10" width="12" customWidth="1"/><col min="11" max="11" width="46" customWidth="1"/></cols>' );
			self::write_raw( $handle, '<sheetData>' );

			foreach ( $rows as $row ) {
				++$row_number;
				self::write_raw( $handle, '<row r="' . $row_number . '">' );

				$column_number = 0;
				foreach ( (array) $row as $value ) {
					++$column_number;
					$cell_ref = self::column_name( $column_number ) . $row_number;
					$style = 1 === $row_number ? 1 : ( in_array( $column_number, array( 5, 6 ), true ) ? 2 : ( 7 === $column_number ? 3 : 0 ) );

					// Keep SKU and product title as real text cells. This prevents Excel from
					// converting numeric-looking SKUs such as 00803003 to 803003.
					$force_string = 1 === $row_number || in_array( $column_number, array( 2, 3, 4, 8, 9, 11 ), true );
					self::write_cell( $handle, $cell_ref, $value, $style, $force_string );
				}

				self::write_raw( $handle, '</row>' );
			}

			self::write_raw( $handle, '</sheetData>' );
			if ( $row_number > 0 ) {
				self::write_raw( $handle, '<autoFilter ref="A1:K' . $row_number . '"/>' );
			}
			self::write_raw( $handle, '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>' );
			self::write_raw( $handle, '</worksheet>' );
		} finally {
			fclose( $handle );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $target_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $sheet_path );
			throw new RuntimeException( 'Unable to create the XLSX archive.' );
		}

		$created = gmdate( 'Y-m-d\TH:i:s\Z' );

		$zip->addFromString( '[Content_Types].xml', self::content_types_xml() );
		$zip->addFromString( '_rels/.rels', self::root_rels_xml() );
		$zip->addFromString( 'docProps/app.xml', self::app_xml() );
		$zip->addFromString( 'docProps/core.xml', self::core_xml( $created ) );
		$zip->addFromString( 'xl/workbook.xml', self::workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels_xml() );
		$zip->addFromString( 'xl/styles.xml', self::styles_xml() );
		$zip->addFile( $sheet_path, 'xl/worksheets/sheet1.xml' );

		if ( ! $zip->close() ) {
			@unlink( $sheet_path );
			throw new RuntimeException( 'Unable to finalize the XLSX archive.' );
		}

		@unlink( $sheet_path );
	}

	/**
	 * Stream rows from the first worksheet.
	 *
	 * @param string   $path     XLSX path.
	 * @param callable $callback Receives ($row, $row_number).
	 * @param int      $max_rows Maximum worksheet rows.
	 * @return int Number of rows read.
	 * @throws RuntimeException On invalid input.
	 */
	public static function read_rows( $path, $callback, $max_rows = 20000 ) {
		$missing = self::missing_requirements();
		if ( ! empty( $missing ) ) {
			throw new RuntimeException( 'Missing PHP extensions: ' . implode( ', ', $missing ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( 'فایل XLSX معتبر نیست یا قابل بازشدن نیست.' );
		}

		try {
			self::validate_archive( $zip );
			$shared_strings = self::read_shared_strings( $zip );
			$sheet_path     = self::resolve_first_sheet_path( $zip );
			$sheet_xml      = $zip->getFromName( $sheet_path );

			if ( false === $sheet_xml ) {
				throw new RuntimeException( 'برگه اول اکسل پیدا نشد.' );
			}
			if ( strlen( $sheet_xml ) > self::MAX_SINGLE_ENTRY_BYTES ) {
				throw new RuntimeException( 'حجم برگه اکسل بیش از حد مجاز است.' );
			}
		} finally {
			$zip->close();
		}

		$reader = new XMLReader();
		$flags  = LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING;
		if ( ! $reader->XML( $sheet_xml, 'UTF-8', $flags ) ) {
			throw new RuntimeException( 'ساختار XML برگه اکسل معتبر نیست.' );
		}

		$row_count = 0;

		while ( $reader->read() ) {
			if ( XMLReader::DOC_TYPE === $reader->nodeType ) {
				$reader->close();
				throw new RuntimeException( 'وجود DTD در فایل اکسل مجاز نیست.' );
			}

			if ( XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) {
				continue;
			}

			++$row_count;
			if ( $row_count > $max_rows ) {
				$reader->close();
				throw new RuntimeException( sprintf( 'حداکثر تعداد ردیف مجاز %d است.', $max_rows ) );
			}

			$row_number = (int) $reader->getAttribute( 'r' );
			if ( $row_number <= 0 ) {
				$row_number = $row_count;
			}

			$row       = array();
			$row_depth = $reader->depth;

			if ( $reader->isEmptyElement ) {
				call_user_func( $callback, $row, $row_number );
				continue;
			}

			while ( $reader->read() ) {
				if ( XMLReader::END_ELEMENT === $reader->nodeType && 'row' === $reader->localName && $reader->depth === $row_depth ) {
					break;
				}

				if ( XMLReader::ELEMENT !== $reader->nodeType || 'c' !== $reader->localName ) {
					continue;
				}

				$cell_ref = (string) $reader->getAttribute( 'r' );
				$cell_type = (string) $reader->getAttribute( 't' );
				$column    = self::column_index_from_ref( $cell_ref );
				$value     = self::read_cell_value( $reader, $cell_type, $shared_strings );

				if ( $column > 0 ) {
					$row[ $column - 1 ] = self::strip_bom( $value );
				}
			}

			if ( ! empty( $row ) ) {
				ksort( $row );
				$max_index = max( array_keys( $row ) );
				for ( $i = 0; $i <= $max_index; $i++ ) {
					if ( ! array_key_exists( $i, $row ) ) {
						$row[ $i ] = '';
					}
				}
				ksort( $row );
				$row = array_values( $row );
			}

			call_user_func( $callback, $row, $row_number );
		}

		$reader->close();
		return $row_count;
	}

	/**
	 * Validate ZIP container and reject active/embedded content.
	 *
	 * @param ZipArchive $zip Archive.
	 * @throws RuntimeException On invalid input.
	 */
	private static function validate_archive( $zip ) {
		if ( $zip->numFiles <= 0 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES ) {
			throw new RuntimeException( 'تعداد اجزای داخلی فایل اکسل غیرمجاز است.' );
		}

		$total_size = 0;
		$required   = array(
			'[Content_Types].xml' => false,
			'xl/workbook.xml'     => false,
		);

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat || empty( $stat['name'] ) ) {
				throw new RuntimeException( 'ساختار داخلی فایل اکسل نامعتبر است.' );
			}

			$name = str_replace( '\\', '/', (string) $stat['name'] );
			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;

			if ( 0 === strpos( $name, '/' ) || false !== strpos( $name, '../' ) || false !== strpos( $name, "\0" ) ) {
				throw new RuntimeException( 'مسیر داخلی ناامن در فایل اکسل شناسایی شد.' );
			}
			if ( $size > self::MAX_SINGLE_ENTRY_BYTES ) {
				throw new RuntimeException( 'یکی از اجزای داخلی فایل اکسل بیش از حد بزرگ است.' );
			}

			$total_size += $size;
			if ( $total_size > self::MAX_UNCOMPRESSED_BYTES ) {
				throw new RuntimeException( 'حجم بازشده فایل اکسل بیش از حد مجاز است.' );
			}

			$lower = strtolower( $name );
			if (
				false !== strpos( $lower, 'vbaproject.bin' ) ||
				0 === strpos( $lower, 'xl/embeddings/' ) ||
				0 === strpos( $lower, 'xl/externallinks/' ) ||
				preg_match( '/\.(exe|dll|js|vbs|bat|cmd|ps1|bin)$/i', $lower )
			) {
				throw new RuntimeException( 'فایل اکسل دارای محتوای فعال یا جاسازی‌شده غیرمجاز است.' );
			}

			if ( array_key_exists( $name, $required ) ) {
				$required[ $name ] = true;
			}
		}

		foreach ( $required as $name => $present ) {
			if ( ! $present ) {
				throw new RuntimeException( 'فایل اکسل ناقص است: ' . $name );
			}
		}
	}

	/**
	 * Read shared strings table.
	 *
	 * @param ZipArchive $zip Archive.
	 * @return array<int,string>
	 */
	private static function read_shared_strings( $zip ) {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml ) {
			return array();
		}
		if ( strlen( $xml ) > self::MAX_SINGLE_ENTRY_BYTES ) {
			throw new RuntimeException( 'جدول رشته‌های فایل اکسل بیش از حد بزرگ است.' );
		}

		$reader = new XMLReader();
		$flags  = LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING;
		if ( ! $reader->XML( $xml, 'UTF-8', $flags ) ) {
			throw new RuntimeException( 'جدول رشته‌های فایل اکسل معتبر نیست.' );
		}

		$strings = array();
		$current = null;
		$si_depth = -1;

		while ( $reader->read() ) {
			if ( XMLReader::DOC_TYPE === $reader->nodeType ) {
				$reader->close();
				throw new RuntimeException( 'وجود DTD در فایل اکسل مجاز نیست.' );
			}

			if ( XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
				$current  = '';
				$si_depth = $reader->depth;
				continue;
			}

			if ( null !== $current && XMLReader::ELEMENT === $reader->nodeType && 't' === $reader->localName ) {
				$current .= $reader->readString();
				continue;
			}

			if ( null !== $current && XMLReader::END_ELEMENT === $reader->nodeType && 'si' === $reader->localName && $reader->depth === $si_depth ) {
				$strings[] = self::strip_bom( $current );
				$current   = null;
				$si_depth  = -1;
			}
		}

		$reader->close();
		return $strings;
	}

	/**
	 * Resolve first worksheet target from workbook relationships.
	 *
	 * @param ZipArchive $zip Archive.
	 * @return string
	 */
	private static function resolve_first_sheet_path( $zip ) {
		$workbook = $zip->getFromName( 'xl/workbook.xml' );
		$rels     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );

		if ( false === $workbook ) {
			throw new RuntimeException( 'ساختار workbook اکسل پیدا نشد.' );
		}

		$relationship_id = '';
		$reader          = new XMLReader();
		$flags           = LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING;

		if ( ! $reader->XML( $workbook, 'UTF-8', $flags ) ) {
			throw new RuntimeException( 'ساختار workbook اکسل معتبر نیست.' );
		}
		while ( $reader->read() ) {
			if ( XMLReader::DOC_TYPE === $reader->nodeType ) {
				$reader->close();
				throw new RuntimeException( 'وجود DTD در فایل اکسل مجاز نیست.' );
			}
			if ( XMLReader::ELEMENT === $reader->nodeType && 'sheet' === $reader->localName ) {
				$relationship_id = (string) $reader->getAttributeNs( 'id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
				if ( '' === $relationship_id ) {
					$relationship_id = (string) $reader->getAttribute( 'r:id' );
				}
				break;
			}
		}
		$reader->close();

		if ( '' === $relationship_id ) {
			return 'xl/worksheets/sheet1.xml';
		}
		if ( false === $rels ) {
			return 'xl/worksheets/sheet1.xml';
		}

		$target = '';
		if ( ! $reader->XML( $rels, 'UTF-8', $flags ) ) {
			throw new RuntimeException( 'روابط workbook اکسل معتبر نیست.' );
		}
		while ( $reader->read() ) {
			if ( XMLReader::DOC_TYPE === $reader->nodeType ) {
				$reader->close();
				throw new RuntimeException( 'وجود DTD در فایل اکسل مجاز نیست.' );
			}
			if ( XMLReader::ELEMENT === $reader->nodeType && 'Relationship' === $reader->localName && $relationship_id === (string) $reader->getAttribute( 'Id' ) ) {
				$target = (string) $reader->getAttribute( 'Target' );
				break;
			}
		}
		$reader->close();

		if ( '' === $target ) {
			return 'xl/worksheets/sheet1.xml';
		}

		$target = str_replace( '\\', '/', $target );
		$target = preg_replace( '#^/+#', '', $target );
		if ( false !== strpos( $target, '../' ) ) {
			throw new RuntimeException( 'مسیر برگه اکسل ناامن است.' );
		}
		if ( 0 !== strpos( $target, 'xl/' ) ) {
			$target = 'xl/' . ltrim( $target, '/' );
		}

		return $target;
	}

	/**
	 * Read one cell. Reader must be positioned on <c>.
	 *
	 * @param XMLReader        $reader         Reader.
	 * @param string           $cell_type      Cell type.
	 * @param array<int,string> $shared_strings Shared strings.
	 * @return string
	 */
	private static function read_cell_value( $reader, $cell_type, $shared_strings ) {
		$cell_depth  = $reader->depth;
		$value       = '';
		$has_formula = false;

		if ( $reader->isEmptyElement ) {
			return '';
		}

		while ( $reader->read() ) {
			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'c' === $reader->localName && $reader->depth === $cell_depth ) {
				break;
			}
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}
			if ( 'f' === $reader->localName ) {
				$has_formula = true;
				$reader->readString();
				continue;
			}
			if ( 'v' === $reader->localName ) {
				$value = $reader->readString();
				continue;
			}
			if ( 't' === $reader->localName && 'inlineStr' === $cell_type ) {
				$value .= $reader->readString();
			}
		}

		if ( $has_formula ) {
			throw new RuntimeException( 'استفاده از فرمول در فایل ورودی مجاز نیست؛ سلول‌ها باید مقدار ثابت داشته باشند.' );
		}

		if ( 's' === $cell_type ) {
			$index = (int) $value;
			return isset( $shared_strings[ $index ] ) ? $shared_strings[ $index ] : '';
		}
		if ( 'b' === $cell_type ) {
			return '1' === $value ? '1' : '0';
		}

		return (string) $value;
	}

	/**
	 * Write a worksheet cell.
	 */
	private static function write_cell( $handle, $ref, $value, $style, $force_string ) {
		$style_attr = $style > 0 ? ' s="' . (int) $style . '"' : '';

		if ( ! $force_string && ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && '' !== $value && preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ) ) ) {
			self::write_raw( $handle, '<c r="' . $ref . '"' . $style_attr . '><v>' . self::xml_escape( (string) $value ) . '</v></c>' );
			return;
		}

		$text = self::strip_bom( (string) $value );
		self::write_raw( $handle, '<c r="' . $ref . '" t="inlineStr"' . $style_attr . '><is><t xml:space="preserve">' . self::xml_escape( $text ) . '</t></is></c>' );
	}

	private static function write_raw( $handle, $data ) {
		$length = strlen( $data );
		$written = fwrite( $handle, $data );
		if ( false === $written || $written !== $length ) {
			throw new RuntimeException( 'Unable to write worksheet data.' );
		}
	}

	private static function xml_escape( $value ) {
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function strip_bom( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
		$value = preg_replace( '/^\x{FEFF}/u', '', $value );
		return $value;
	}

	private static function column_index_from_ref( $ref ) {
		if ( ! preg_match( '/^([A-Z]+)\d+$/i', $ref, $matches ) ) {
			return 0;
		}
		$letters = strtoupper( $matches[1] );
		$index   = 0;
		$length  = strlen( $letters );
		for ( $i = 0; $i < $length; $i++ ) {
			$index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
		}
		return $index;
	}

	private static function column_name( $number ) {
		$name = '';
		while ( $number > 0 ) {
			$number--;
			$name   = chr( 65 + ( $number % 26 ) ) . $name;
			$number = (int) floor( $number / 26 );
		}
		return $name;
	}

	private static function content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
			. '</Types>';
	}

	private static function root_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			. '</Relationships>';
	}

	private static function workbook_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<workbookPr/>'
			. '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
			. '<sheets><sheet name="محصولات" sheetId="1" r:id="rId1"/></sheets>'
			. '<calcPr calcId="0" fullCalcOnLoad="0"/>'
			. '</workbook>';
	}

	private static function workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	private static function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="0.##"/></numFmts>'
			. '<fonts count="2"><font><sz val="11"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/><family val="2"/></font></fonts>'
			. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2271B1"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="4">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
			. '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	private static function core_xml( $created ) {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:creator>Webtanan WooCommerce Excel Manager</dc:creator>'
			. '<cp:lastModifiedBy>Webtanan WooCommerce Excel Manager</cp:lastModifiedBy>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . self::xml_escape( $created ) . '</dcterms:created>'
			. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . self::xml_escape( $created ) . '</dcterms:modified>'
			. '</cp:coreProperties>';
	}

	private static function app_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
			. '<Application>Webtanan WooCommerce Excel Manager</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
			. '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
			. '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>محصولات</vt:lpstr></vt:vector></TitlesOfParts>'
			. '<Company>Webtanan</Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>2.0</AppVersion>'
			. '</Properties>';
	}
}
