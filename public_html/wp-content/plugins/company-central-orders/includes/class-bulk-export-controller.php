<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Bulk_Export_Controller {

	const MAX_ORDERS = 200;
	const MAX_ROWS   = 5000;

	public function hooks() {
		add_action( 'admin_post_company_orders_bulk_export', array( $this, 'handle_export' ) );
	}

	public function handle_export() {
		if ( ! current_user_can( Company_Central_Orders_Access::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-central-orders' ), 403 );
		}

		$nonce = isset( $_POST['_cco_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cco_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'company_orders_bulk_export' ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر یا منقضی شده است.', 'company-central-orders' ), 400 );
		}

		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
		if ( ! in_array( $format, array( 'pdf', 'docx', 'xlsx' ), true ) ) {
			wp_die( esc_html__( 'فرمت خروجی معتبر نیست.', 'company-central-orders' ), 400 );
		}

		$orders = $this->selected_orders();
		$rows   = $this->build_rows( $orders );
		if ( ! $rows ) {
			wp_die( esc_html__( 'سفارش‌های انتخاب‌شده قلم قابل‌خروجی ندارند.', 'company-central-orders' ), 400 );
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			wp_die( sprintf( esc_html__( 'حداکثر %d ردیف کالا در هر خروجی مجاز است.', 'company-central-orders' ), self::MAX_ROWS ), 400 );
		}

		if ( 'pdf' === $format ) {
			$this->output_pdf( $rows );
		}
		if ( 'xlsx' === $format ) {
			$this->output_xlsx( $rows );
		}

		$this->output_docx( $rows );
	}

	private function selected_orders() {
		$raw_ids = isset( $_POST['order_ids'] ) ? (array) wp_unslash( $_POST['order_ids'] ) : array();
		$ids     = array_values( array_filter( array_unique( array_map( 'absint', $raw_ids ) ) ) );

		if ( ! $ids ) {
			wp_die( esc_html__( 'حداقل یک سفارش را انتخاب کنید.', 'company-central-orders' ), 400 );
		}
		if ( count( $ids ) > self::MAX_ORDERS ) {
			wp_die( sprintf( esc_html__( 'حداکثر %d سفارش در هر خروجی مجاز است.', 'company-central-orders' ), self::MAX_ORDERS ), 400 );
		}

		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if (
				! $order instanceof WC_Order
				|| 'shop_order' !== $order->get_type()
				|| ! $order->get_meta( '_company_source_store', true )
				|| ! Company_Central_Orders_Access::order_visible_to_current_user( $order )
			) {
				continue;
			}
			$orders[] = $order;
		}

		if ( ! $orders ) {
			wp_die( esc_html__( 'سفارش همگام‌شده معتبری پیدا نشد.', 'company-central-orders' ), 404 );
		}
		if ( count( $orders ) !== count( $ids ) ) {
			wp_die( 'بعضی سفارش‌های انتخاب‌شده حذف شده‌اند یا قابل دسترسی نیستند. فهرست را تازه‌سازی و دوباره انتخاب کنید؛ خروجی ناقص تولید نشد.', 409 );
		}

		return $orders;
	}

	private function build_rows( array $orders ) {
		$rows      = array();
		$item_row  = 0;
		$order_row = 0;

		foreach ( $orders as $order ) {
			$quantity_rows = $order->get_items( 'line_item' );
			if ( ! $quantity_rows ) {
				continue;
			}
			++$order_row;
			$group_size = count( $quantity_rows );
			$group_row  = 0;
			foreach ( $quantity_rows as $item ) {
				++$item_row;
				++$group_row;
				$quantity = (float) $item->get_quantity();
				$line     = (float) $item->get_total() + (float) $item->get_total_tax();
				$unit     = $quantity > 0 ? $line / $quantity : $line;

				$rows[] = array(
					'order_row' => $order_row,
					'item_row'  => $item_row,
					'group_start' => 1 === $group_row,
					'group_size'  => $group_size,
					'product'   => $item->get_name(),
					'quantity'  => $this->format_quantity( $quantity ),
					'price'     => $this->toman_price( $unit, $order ),
					'order_total' => $this->toman_price( (float) $order->get_total(), $order ),
					'payment'   => $order->get_payment_method_title() ?: '—',
					'shipping'  => $order->get_shipping_method() ?: '—',
					'address'   => $this->short_address( $order ),
					'order'     => sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ) ?: absint( $order->get_meta( '_company_source_order_id', true ) ),
					'store'     => $this->store_name( sanitize_key( $order->get_meta( '_company_source_store', true ) ) ),
					'customer'  => trim( $order->get_formatted_billing_full_name() ) ?: trim( $order->get_formatted_shipping_full_name() ) ?: '—',
				);
			}
		}

		return $rows;
	}

	private function format_quantity( $quantity ) {
		$quantity = (float) $quantity;
		if ( floor( $quantity ) === $quantity ) {
			return number_format_i18n( (int) $quantity );
		}

		return rtrim( rtrim( number_format_i18n( $quantity, 2 ), '0' ), '.،' );
	}

	private function toman_price( $amount, WC_Order $order ) {
		return number_format_i18n( round( $this->toman_value( $amount, $order ) ), 0 );
	}

	private function toman_value( $amount, WC_Order $order ) {
		$currency = strtoupper( (string) $order->get_currency() );
		$value    = 'IRR' === $currency ? (float) $amount / 10 : (float) $amount;
		$value    = (float) apply_filters( 'company_central_orders_export_toman_price', $value, $amount, $currency, $order );
		return $value;
	}

	private function short_address( WC_Order $order ) {
		$city    = $order->get_shipping_city() ?: $order->get_billing_city();
		$state   = $order->get_shipping_state() ?: $order->get_billing_state();
		$country = $order->get_shipping_country() ?: $order->get_billing_country();
		$states  = WC()->countries ? WC()->countries->get_states( $country ) : array();
		$state   = isset( $states[ $state ] ) ? $states[ $state ] : $state;

		$parts = array_values( array_filter( array_map( 'sanitize_text_field', array( $state, $city ) ) ) );
		return $parts ? implode( '، ', array_unique( $parts ) ) : '—';
	}

	private function store_name( $store_id ) {
		$names = array(
			'site1' => 'zoom bazar',
			'site2' => 'smart pishro',
		);
		$names = apply_filters( 'company_central_orders_export_store_names', $names );

		return isset( $names[ $store_id ] ) ? sanitize_text_field( $names[ $store_id ] ) : $store_id;
	}

	private function output_pdf( array $rows ) {
		$autoload = COMPANY_CENTRAL_ORDERS_PATH . 'vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			wp_die( esc_html__( 'کتابخانه PDF در بسته افزونه موجود نیست. افزونه را از ZIP کامل نصب کنید.', 'company-central-orders' ), 500 );
		}
		if ( ! extension_loaded( 'mbstring' ) || ! extension_loaded( 'gd' ) ) {
			wp_die( esc_html__( 'برای ساخت PDF، افزونه‌های PHP mbstring و gd باید روی هاست فعال باشند.', 'company-central-orders' ), 500 );
		}

		require_once $autoload;
		$temp_dir = trailingslashit( get_temp_dir() ) . 'company-central-orders-mpdf';
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			wp_die( esc_html__( 'پوشه موقت PDF قابل ساخت نیست.', 'company-central-orders' ), 500 );
		}

		try {
			$mpdf = new \Mpdf\Mpdf(
				array(
					'mode'              => 'utf-8',
					'format'            => 'A4-L',
					'default_font'      => 'dejavusanscondensed',
					'tempDir'           => $temp_dir,
					'margin_left'       => 7,
					'margin_right'      => 7,
					'margin_top'        => 10,
					'margin_bottom'     => 11,
					'margin_header'     => 4,
					'margin_footer'     => 4,
				)
			);
			$mpdf->SetDirectionality( 'rtl' );
			$mpdf->autoScriptToLang = false;
			$mpdf->autoLangToFont   = false;
			$mpdf->SetTitle( 'خروجی سفارش‌های انتخاب‌شده' );
			$mpdf->SetAuthor( 'Company Central Orders' );
			$mpdf->SetHTMLFooter( '<div style="font-size:8pt;color:#6b7280;text-align:center">صفحه {PAGENO} از {nbpg}</div>' );
			$mpdf->WriteHTML( $this->pdf_html( $rows ) );
			$this->clean_output_buffers();
			$mpdf->Output( $this->filename( 'pdf' ), \Mpdf\Output\Destination::DOWNLOAD );
			exit;
		} catch ( Throwable $error ) {
			wp_die( esc_html( 'ساخت PDF ناموفق بود: ' . $error->getMessage() ), 500 );
		}
	}

	private function pdf_html( array $rows ) {
		$headers = $this->headers();
		$html    = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>';
		$html   .= 'body{font-family:dejavusanscondensed;font-size:8pt;color:#111827;direction:rtl}h1{font-size:14pt;margin:0 0 3mm;text-align:right}.meta{font-size:8pt;color:#64748b;margin-bottom:3mm}table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}th,td{border:.2mm solid #aeb7c2;padding:1.6mm 1mm;vertical-align:middle;text-align:center;overflow-wrap:break-word}th{background:#e8eef5;font-weight:bold;font-size:7.6pt}td.product,td.customer{text-align:right}.store{display:block;color:#64748b;font-size:7pt;margin-top:.8mm;direction:ltr}.number{direction:ltr;font-weight:bold}.c-order_row{width:5%}.c-item_row{width:5%}.c-product{width:15%}.c-quantity{width:5%}.c-price{width:8%}.c-order_total{width:10%}.c-payment{width:9%}.c-shipping{width:10%}.c-address{width:9%}.c-order{width:10%}.c-customer{width:14%}</style></head><body>';
		$html   .= '<h1>فهرست کالاهای سفارش‌های انتخاب‌شده</h1><div class="meta">تعداد ردیف‌ها: ' . esc_html( number_format_i18n( count( $rows ) ) ) . ' | تاریخ تهیه: ' . esc_html( Company_Central_Orders_Jalali_Date::format_timestamp( time() ) ) . '</div>';
		$html   .= '<table><thead><tr>';
		foreach ( $headers as $index => $header ) {
			$html .= '<th class="c-' . esc_attr( $index ) . '">' . esc_html( $header ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $data ) {
			$html .= '<tr>';
			if ( $data['group_start'] ) {
				$html .= '<td rowspan="' . absint( $data['group_size'] ) . '">' . esc_html( $data['order_row'] ) . '</td>';
			}
			$html .= '<td>' . esc_html( $data['item_row'] ) . '</td><td class="product">' . esc_html( $data['product'] ) . '</td><td>' . esc_html( $data['quantity'] ) . '</td><td>' . esc_html( $data['price'] ) . '</td>';
			if ( $data['group_start'] ) {
				$html .= '<td rowspan="' . absint( $data['group_size'] ) . '">' . esc_html( $data['order_total'] ) . ' تومان</td>';
			}
			$html .= '<td>' . esc_html( $data['payment'] ) . '</td><td>' . esc_html( $data['shipping'] ) . '</td><td>' . esc_html( $data['address'] ) . '</td><td><div class="number">' . esc_html( $data['order'] ) . '</div><div class="store">' . esc_html( $data['store'] ) . '</div></td><td class="customer">' . esc_html( $data['customer'] ) . '</td></tr>';
		}
		$html .= '</tbody></table></body></html>';

		return $html;
	}

	private function output_docx( array $rows ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'برای ساخت Word، افزونه PHP ZipArchive باید روی هاست فعال باشد.', 'company-central-orders' ), 500 );
		}

		$temp = tempnam( get_temp_dir(), 'cco-docx-' );
		if ( ! $temp ) {
			wp_die( esc_html__( 'فایل موقت Word قابل ساخت نیست.', 'company-central-orders' ), 500 );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $temp );
			wp_die( esc_html__( 'ساخت بسته Word ناموفق بود.', 'company-central-orders' ), 500 );
		}

		$zip->addFromString( '[Content_Types].xml', $this->docx_content_types() );
		$zip->addFromString( '_rels/.rels', $this->docx_root_relationships() );
		$zip->addFromString( 'docProps/core.xml', $this->docx_core_properties() );
		$zip->addFromString( 'docProps/app.xml', $this->docx_app_properties() );
		$zip->addFromString( 'word/document.xml', $this->docx_document( $rows ) );
		$zip->addFromString( 'word/styles.xml', $this->docx_styles() );
		$zip->addFromString( 'word/_rels/document.xml.rels', $this->docx_document_relationships() );
		$zip->close();

		$filename = $this->filename( 'docx' );
		$this->clean_output_buffers();
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $temp ) );
		readfile( $temp );
		@unlink( $temp );
		exit;
	}

	private function output_xlsx( array $rows ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'برای ساخت Excel، افزونه PHP ZipArchive باید روی هاست فعال باشد.', 'company-central-orders' ), 500 );
		}

		$temp = tempnam( get_temp_dir(), 'cco-xlsx-' );
		if ( ! $temp ) {
			wp_die( esc_html__( 'فایل موقت Excel قابل ساخت نیست.', 'company-central-orders' ), 500 );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $temp );
			wp_die( esc_html__( 'ساخت بسته Excel ناموفق بود.', 'company-central-orders' ), 500 );
		}

		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="سفارش‌ها" sheetId="1" r:id="rId1"/></sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		$zip->addFromString( 'xl/styles.xml', $this->xlsx_styles() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $this->xlsx_sheet( $rows ) );
		$zip->close();

		$filename = $this->filename( 'xlsx' );
		$this->clean_output_buffers();
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $temp ) );
		readfile( $temp );
		@unlink( $temp );
		exit;
	}

	private function xlsx_sheet( array $rows ) {
		$keys    = array_keys( $this->headers() );
		$letters = range( 'A', 'K' );
		$widths  = array( 10, 9, 34, 9, 15, 17, 18, 20, 17, 17, 23 );
		$merges  = array();
		$xml     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
		foreach ( $widths as $index => $width ) {
			$column = $index + 1;
			$xml   .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
		}
		$xml .= '</cols><sheetData><row r="1" ht="28" customHeight="1">';
		foreach ( array_values( $this->headers() ) as $index => $header ) {
			$xml .= $this->xlsx_cell( $letters[ $index ] . '1', $header, 1 );
		}
		$xml .= '</row>';
		foreach ( $rows as $row_index => $data ) {
			$sheet_row = $row_index + 2;
			if ( $data['group_start'] && $data['group_size'] > 1 ) {
				$merges[] = 'A' . $sheet_row . ':A' . ( $sheet_row + absint( $data['group_size'] ) - 1 );
				$merges[] = 'F' . $sheet_row . ':F' . ( $sheet_row + absint( $data['group_size'] ) - 1 );
			}
			$xml      .= '<row r="' . $sheet_row . '" ht="34" customHeight="1">';
			foreach ( $keys as $index => $key ) {
				$value = 'order' === $key ? $data['order'] . "\n" . $data['store'] : $data[ $key ];
				if ( 'order_row' === $key && ! $data['group_start'] ) {
					$value = '';
				}
				if ( 'order_total' === $key ) {
					$value = $data['group_start'] ? $data['order_total'] . ' تومان' : '';
				}
				$xml  .= $this->xlsx_cell( $letters[ $index ] . $sheet_row, $value, 0 );
			}
			$xml .= '</row>';
		}
		$xml .= '</sheetData>';
		$last = count( $rows ) + 1;
		$xml .= '<autoFilter ref="A1:K' . $last . '"/>';
		if ( $merges ) {
			$xml .= '<mergeCells count="' . count( $merges ) . '">';
			foreach ( $merges as $merge ) {
				$xml .= '<mergeCell ref="' . esc_attr( $merge ) . '"/>';
			}
			$xml .= '</mergeCells>';
		}
		return $xml . '<pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
	}

	private function xlsx_cell( $reference, $value, $style ) {
		return '<c r="' . esc_attr( $reference ) . '" t="inlineStr" s="' . absint( $style ) . '"><is><t xml:space="preserve">' . $this->xml( $value ) . '</t></is></c>';
	}

	private function xlsx_styles() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="10"/><name val="Tahoma"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font><font><b/><sz val="10"/><name val="Tahoma"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1677B8"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEDF8F2"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left style="thin"><color rgb="FFD7DEE7"/></left><right style="thin"><color rgb="FFD7DEE7"/></right><top style="thin"><color rgb="FFD7DEE7"/></top><bottom style="thin"><color rgb="FFD7DEE7"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1" readingOrder="2"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1" readingOrder="2"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1" readingOrder="2"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}

	private function docx_document( array $rows ) {
		$headers = $this->headers();
		$widths  = array( 'order_row'=>650, 'item_row'=>650, 'product'=>2600, 'quantity'=>600, 'price'=>1100, 'order_total'=>1300, 'payment'=>1400, 'shipping'=>1400, 'address'=>1300, 'order'=>1500, 'customer'=>3200 );
		$xml     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
		$xml    .= $this->docx_paragraph( 'فهرست کالاهای سفارش‌های انتخاب‌شده', 'Title', 'right' );
		$xml    .= $this->docx_paragraph( 'تعداد ردیف‌ها: ' . number_format_i18n( count( $rows ) ) . ' | تاریخ تهیه: ' . Company_Central_Orders_Jalali_Date::format_timestamp( time() ), 'Meta', 'right' );
		$xml    .= '<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="15700" w:type="dxa"/><w:tblInd w:w="0" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="AEB7C2"/><w:left w:val="single" w:sz="4" w:color="AEB7C2"/><w:bottom w:val="single" w:sz="4" w:color="AEB7C2"/><w:right w:val="single" w:sz="4" w:color="AEB7C2"/><w:insideH w:val="single" w:sz="4" w:color="C7CED6"/><w:insideV w:val="single" w:sz="4" w:color="C7CED6"/></w:tblBorders><w:tblCellMar><w:top w:w="80" w:type="dxa"/><w:left w:w="80" w:type="dxa"/><w:bottom w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
		foreach ( $widths as $width ) {
			$xml .= '<w:gridCol w:w="' . $width . '"/>';
		}
		$xml .= '</w:tblGrid><w:tr><w:trPr><w:tblHeader/></w:trPr>';
		foreach ( $headers as $key => $header ) {
			$xml .= $this->docx_cell( $header, $widths[ $key ], true, 'center' );
		}
		$xml .= '</w:tr>';
		foreach ( $rows as $data ) {
			$xml .= '<w:tr>';
			$xml .= $this->docx_vmerge_cell( $data['group_start'] ? $data['order_row'] : '', $widths['order_row'], $data['group_start'] );
			$xml .= $this->docx_cell( $data['item_row'], $widths['item_row'], false, 'center' );
			$xml .= $this->docx_cell( $data['product'], $widths['product'], false, 'right' );
			$xml .= $this->docx_cell( $data['quantity'], $widths['quantity'], false, 'center' );
			$xml .= $this->docx_cell( $data['price'], $widths['price'], false, 'center' );
			$xml .= $this->docx_vmerge_cell( $data['group_start'] ? $data['order_total'] . ' تومان' : '', $widths['order_total'], $data['group_start'] );
			$xml .= $this->docx_cell( $data['payment'], $widths['payment'], false, 'center' );
			$xml .= $this->docx_cell( $data['shipping'], $widths['shipping'], false, 'center' );
			$xml .= $this->docx_cell( $data['address'], $widths['address'], false, 'center' );
			$xml .= $this->docx_order_cell( $data['order'], $data['store'], $widths['order'] );
			$xml .= $this->docx_cell( $data['customer'], $widths['customer'], false, 'right' );
			$xml .= '</w:tr>';
		}
		$xml .= '</w:tbl><w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567" w:header="300" w:footer="300" w:gutter="0"/></w:sectPr></w:body></w:document>';

		return $xml;
	}

	private function docx_cell( $value, $width, $header = false, $alignment = 'center' ) {
		$shade = $header ? '<w:shd w:val="clear" w:color="auto" w:fill="E8EEF5"/>' : '';
		$bold  = $header ? '<w:b/>' : '';
		return '<w:tc><w:tcPr><w:tcW w:w="' . absint( $width ) . '" w:type="dxa"/><w:vAlign w:val="center"/>' . $shade . '</w:tcPr><w:p><w:pPr><w:bidi/><w:jc w:val="' . esc_attr( $alignment ) . '"/><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr>' . $bold . '<w:rtl/><w:lang w:bidi="fa-IR"/></w:rPr><w:t xml:space="preserve">' . $this->xml( $value ) . '</w:t></w:r></w:p></w:tc>';
	}

	private function docx_vmerge_cell( $value, $width, $restart ) {
		$merge = $restart ? '<w:vMerge w:val="restart"/>' : '<w:vMerge/>';
		return '<w:tc><w:tcPr><w:tcW w:w="' . absint( $width ) . '" w:type="dxa"/><w:vAlign w:val="center"/>' . $merge . '</w:tcPr><w:p><w:pPr><w:bidi/><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:rtl/></w:rPr><w:t>' . $this->xml( $value ) . '</w:t></w:r></w:p></w:tc>';
	}

	private function docx_order_cell( $number, $store, $width ) {
		return '<w:tc><w:tcPr><w:tcW w:w="' . absint( $width ) . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'
			. '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/><w:spacing w:after="30"/></w:pPr><w:r><w:rPr><w:b/><w:rtl/></w:rPr><w:t>' . $this->xml( $number ) . '</w:t></w:r></w:p>'
			. '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="14"/><w:szCs w:val="14"/><w:color w:val="64748B"/></w:rPr><w:t>' . $this->xml( $store ) . '</w:t></w:r></w:p></w:tc>';
	}

	private function docx_paragraph( $text, $style, $alignment ) {
		return '<w:p><w:pPr><w:pStyle w:val="' . esc_attr( $style ) . '"/><w:bidi/><w:jc w:val="' . esc_attr( $alignment ) . '"/></w:pPr><w:r><w:rPr><w:rtl/><w:lang w:bidi="fa-IR"/></w:rPr><w:t>' . $this->xml( $text ) . '</w:t></w:r></w:p>';
	}

	private function docx_styles() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Tahoma" w:hAnsi="Tahoma" w:cs="Tahoma"/><w:sz w:val="17"/><w:szCs w:val="17"/><w:lang w:val="fa-IR" w:bidi="fa-IR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:bidi/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Meta"/><w:qFormat/><w:pPr><w:spacing w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/><w:color w:val="1F2937"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Meta"><w:name w:val="Meta"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/><w:color w:val="64748B"/></w:rPr></w:style></w:styles>';
	}

	private function docx_content_types() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
	}

	private function docx_root_relationships() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
	}

	private function docx_document_relationships() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
	}

	private function docx_core_properties() {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>خروجی سفارش‌های انتخاب‌شده</dc:title><dc:creator>Company Central Orders</dc:creator><cp:lastModifiedBy>Company Central Orders</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>';
	}

	private function docx_app_properties() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Company Central Orders</Application><AppVersion>0.8.0</AppVersion></Properties>';
	}

	private function headers() {
		return array(
			'order_row' => 'ردیف سفارش',
			'item_row'  => 'ردیف کالا',
			'product'  => 'نام کالا',
			'quantity' => 'تعداد',
			'price'    => 'قیمت (تومان)',
			'order_total' => 'جمع مبلغ',
			'payment'  => 'روش پرداخت',
			'shipping' => 'روش ارسال',
			'address'  => 'آدرس',
			'order'    => 'شماره سفارش',
			'customer' => 'نام و نام خانوادگی',
		);
	}

	private function filename( $extension ) {
		return 'company-orders-' . wp_date( 'Ymd-His' ) . '.' . $extension;
	}

	private function xml( $value ) {
		return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private function clean_output_buffers() {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
	}
}
