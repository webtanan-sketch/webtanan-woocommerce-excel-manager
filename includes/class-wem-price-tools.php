<?php
/**
 * Category percentage rules and inline price editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Tools {
	const CAPABILITY       = 'manage_woocommerce';
	const BULK_PAGE_SLUG   = 'webtanan-category-price-manager';
	const EDITOR_PAGE_SLUG = 'webtanan-inline-price-manager';
	const PREVIEW_TTL      = 1800;
	const PAGE_SIZE        = 80;
	const MAX_FORM_ROWS    = 120;
	const MAX_BULK_CHANGES = 10000;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
		add_action( 'admin_post_wem_bulk_price_preview', array( __CLASS__, 'handle_bulk_preview' ) );
		add_action( 'admin_post_wem_bulk_price_apply', array( __CLASS__, 'handle_bulk_apply' ) );
		add_action( 'admin_post_wem_inline_price_save', array( __CLASS__, 'handle_inline_save' ) );
	}

	public static function admin_menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			'تغییر درصدی قیمت محصولات',
			'تغییر درصدی قیمت',
			self::CAPABILITY,
			self::BULK_PAGE_SLUG,
			array( __CLASS__, 'render_bulk_page' )
		);

		add_submenu_page(
			'edit.php?post_type=product',
			'ویرایش جدولی قیمت محصولات',
			'ویرایش جدولی قیمت',
			self::CAPABILITY,
			self::EDITOR_PAGE_SLUG,
			array( __CLASS__, 'render_editor_page' )
		);
	}

	public static function render_bulk_page() {
		self::assert_access();
		$token  = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : '';
		$notice = self::pull_notice( 'bulk' );
		?>
		<div class="wrap wem-wrap" dir="rtl">
			<h1>تغییر درصدی قیمت بر اساس دسته‌بندی</h1>
			<?php self::render_styles(); ?>
			<?php self::render_notice( $notice ); ?>
			<div class="wem-card">
				<p class="wem-help">ابتدا دسته‌بندی و قانون تغییر قیمت را تعیین کنید. هیچ قیمتی بدون نمایش پیش‌نمایش و تأیید نهایی تغییر نمی‌کند. برای محصولات متغیر، قیمت تنوع‌ها محاسبه می‌شود.</p>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="wem_bulk_price_preview">
					<?php wp_nonce_field( 'wem_bulk_price_preview' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="wem_category">دسته‌بندی</label></th><td><?php self::category_dropdown( 'category_id', 0, true ); ?></td></tr>
						<tr><th>زیر‌دسته‌ها</th><td><label><input type="checkbox" name="include_children" value="1" checked> محصولات زیر‌دسته‌ها نیز محاسبه شوند</label></td></tr>
						<tr><th>نوع عملیات</th><td><select name="direction"><option value="increase">افزایش</option><option value="decrease">کاهش</option></select></td></tr>
						<tr><th><label for="wem_percentage">درصد</label></th><td><input id="wem_percentage" type="number" name="percentage" min="0.01" max="1000" step="0.01" required> ٪</td></tr>
						<tr><th>قیمت‌های هدف</th><td><label><input type="checkbox" name="targets[]" value="regular" checked> قیمت عادی</label><br><label><input type="checkbox" name="targets[]" value="sale"> قیمت حراج</label></td></tr>
						<tr><th>گرد کردن</th><td><select name="round_to"><option value="1">بدون گرد کردن خاص</option><option value="10">نزدیک‌ترین ۱۰</option><option value="100">نزدیک‌ترین ۱۰۰</option><option value="1000">نزدیک‌ترین ۱٬۰۰۰</option><option value="10000">نزدیک‌ترین ۱۰٬۰۰۰</option></select></td></tr>
					</table>
					<p><button type="submit" class="button button-primary">محاسبه و نمایش پیش‌نمایش</button></p>
				</form>
			</div>
			<?php self::render_bulk_preview( $token ); ?>
		</div>
		<?php
	}

	public static function render_editor_page() {
		self::assert_access();
		$category_id     = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0;
		$include_children = ! isset( $_GET['include_children'] ) || '0' !== (string) $_GET['include_children'];
		$page             = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$notice           = self::pull_notice( 'editor' );
		?>
		<div class="wrap wem-wrap" dir="rtl">
			<h1>ویرایش جدولی قیمت و موجودی</h1>
			<?php self::render_styles(); ?>
			<?php self::render_notice( $notice ); ?>
			<div class="wem-card">
				<form method="get">
					<input type="hidden" name="post_type" value="product">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::EDITOR_PAGE_SLUG ); ?>">
					<div class="wem-actions">
						<label><strong>دسته‌بندی:</strong> <?php self::category_dropdown( 'category_id', $category_id, true ); ?></label>
						<label><input type="checkbox" name="include_children" value="1" <?php checked( $include_children ); ?>> شامل زیر‌دسته‌ها</label>
						<button class="button button-primary" type="submit">دریافت لیست محصولات</button>
					</div>
				</form>
			</div>
			<?php
			if ( $category_id ) {
				self::render_editor_table( $category_id, $include_children, $page );
			} else {
				echo '<div class="wem-card"><p class="wem-help">یک دسته‌بندی انتخاب کنید و دکمه «دریافت لیست محصولات» را بزنید.</p></div>';
			}
			?>
		</div>
		<?php
	}

	public static function handle_bulk_preview() {
		self::assert_access();
		check_admin_referer( 'wem_bulk_price_preview' );

		$category_id     = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$include_children = ! empty( $_POST['include_children'] );
		$direction       = isset( $_POST['direction'] ) && 'decrease' === $_POST['direction'] ? 'decrease' : 'increase';
		$percentage      = isset( $_POST['percentage'] ) ? self::parse_decimal( wp_unslash( $_POST['percentage'] ) ) : null;
		$round_to        = isset( $_POST['round_to'] ) ? absint( $_POST['round_to'] ) : 1;
		$targets_raw     = isset( $_POST['targets'] ) ? (array) wp_unslash( $_POST['targets'] ) : array();
		$targets         = array_values( array_intersect( array( 'regular', 'sale' ), array_map( 'sanitize_key', $targets_raw ) ) );

		if ( ! $category_id || ! term_exists( $category_id, 'product_cat' ) ) {
			self::redirect_notice( 'bulk', 'error', 'یک دسته‌بندی معتبر انتخاب کنید.' );
		}
		if ( null === $percentage || $percentage <= 0 || $percentage > 1000 ) {
			self::redirect_notice( 'bulk', 'error', 'درصد باید عددی بیشتر از صفر و حداکثر ۱۰۰۰ باشد.' );
		}
		if ( empty( $targets ) ) {
			self::redirect_notice( 'bulk', 'error', 'حداقل یکی از قیمت‌های عادی یا حراج را انتخاب کنید.' );
		}
		if ( ! in_array( $round_to, array( 1, 10, 100, 1000, 10000 ), true ) ) {
			$round_to = 1;
		}

		try {
			$items   = self::products_for_category( $category_id, $include_children, true );
			$changes = array();
			$reports = array();
			$errors  = array();
			$factor  = 'decrease' === $direction ? ( 1 - ( $percentage / 100 ) ) : ( 1 + ( $percentage / 100 ) );

			if ( $factor < 0 ) {
				throw new RuntimeException( 'این درصد کاهش باعث منفی‌شدن قیمت می‌شود.' );
			}

			foreach ( $items as $product ) {
				if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) ) {
					continue;
				}
				$id = $product->get_id();
				if ( ! current_user_can( 'edit_post', $id ) ) {
					$errors[] = array( 'id' => $id, 'message' => 'اجازه ویرایش این محصول را ندارید.' );
					continue;
				}

				$old_regular = (string) $product->get_regular_price( 'edit' );
				$old_sale    = (string) $product->get_sale_price( 'edit' );
				$new_regular = $old_regular;
				$new_sale    = $old_sale;
				$field_changes = array();

				if ( in_array( 'regular', $targets, true ) && '' !== $old_regular ) {
					$new_regular = self::calculate_price( $old_regular, $factor, $round_to );
					if ( ! self::decimal_equal( $new_regular, $old_regular ) ) {
						$field_changes['regular_price'] = array( 'old' => $old_regular, 'new' => $new_regular );
					}
				}
				if ( in_array( 'sale', $targets, true ) && '' !== $old_sale ) {
					$new_sale = self::calculate_price( $old_sale, $factor, $round_to );
					if ( ! self::decimal_equal( $new_sale, $old_sale ) ) {
						$field_changes['sale_price'] = array( 'old' => $old_sale, 'new' => $new_sale );
					}
				}

				if ( '' !== $new_sale && '' !== $new_regular && (float) $new_sale >= (float) $new_regular ) {
					$errors[] = array( 'id' => $id, 'message' => 'قیمت حراج پس از محاسبه باید کمتر از قیمت عادی باشد.' );
					continue;
				}
				if ( empty( $field_changes ) ) {
					continue;
				}

				if ( count( $changes ) >= self::MAX_BULK_CHANGES ) {
					throw new RuntimeException( 'تعداد تغییرات از حد مجاز بیشتر است؛ دسته‌بندی کوچک‌تری انتخاب کنید.' );
				}
				$snapshot = self::price_snapshot( $product );
				$changes[] = array( 'id' => $id, 'parent_id' => $product->get_parent_id(), 'snapshot' => $snapshot, 'changes' => $field_changes );
				$reports[] = array(
					'id'         => $id,
					'sku'        => (string) $product->get_sku( 'edit' ),
					'name'       => self::display_name( $product ),
					'categories' => self::category_names( $product ),
					'changes'    => $field_changes,
				);
			}

			$token = strtolower( wp_generate_password( 24, false, false ) );
			set_transient(
				self::bulk_preview_key( $token ),
				array(
					'user_id'    => get_current_user_id(),
					'created_at' => time(),
					'changes'    => $changes,
					'reports'    => $reports,
					'errors'     => $errors,
					'settings'   => array(
						'category_id'      => $category_id,
						'include_children' => $include_children,
						'direction'        => $direction,
						'percentage'       => $percentage,
						'targets'          => $targets,
						'round_to'         => $round_to,
					),
				),
				self::PREVIEW_TTL
			);

			wp_safe_redirect( add_query_arg( array( 'post_type' => 'product', 'page' => self::BULK_PAGE_SLUG, 'preview' => $token ), admin_url( 'edit.php' ) ) );
			exit;
		} catch ( Throwable $e ) {
			self::redirect_notice( 'bulk', 'error', 'خطا در محاسبه پیش‌نمایش: ' . $e->getMessage() );
		}
	}

	public static function handle_bulk_apply() {
		self::assert_access();
		check_admin_referer( 'wem_bulk_price_apply' );
		$token = isset( $_POST['preview_token'] ) ? sanitize_key( wp_unslash( $_POST['preview_token'] ) ) : '';
		$data  = get_transient( self::bulk_preview_key( $token ) );
		if ( ! is_array( $data ) || empty( $data['changes'] ) || ! empty( $data['errors'] ) || (int) $data['user_id'] !== get_current_user_id() ) {
			self::redirect_notice( 'bulk', 'error', 'پیش‌نمایش معتبر نیست یا منقضی شده است.' );
		}

		$lock_key      = 'wem_bulk_apply_lock_' . md5( get_current_user_id() . '|' . $token );
		$existing_lock = (int) get_option( $lock_key, 0 );
		if ( $existing_lock && ( time() - $existing_lock ) > 15 * MINUTE_IN_SECONDS ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			self::redirect_notice( 'bulk', 'error', 'این پیش‌نمایش هم‌اکنون در حال اعمال است یا قبلاً اجرا شده است.' );
		}

		$updated = 0;
		$skipped = 0;
		$errors  = array();
		$parents = array();
		foreach ( $data['changes'] as $item ) {
			$id = absint( $item['id'] );
			try {
				$product = wc_get_product( $id );
				if ( ! $product || ! current_user_can( 'edit_post', $id ) ) {
					throw new RuntimeException( 'محصول پیدا نشد یا اجازه ویرایش ندارید.' );
				}
				if ( ! self::price_snapshot_matches( self::price_snapshot( $product ), $item['snapshot'] ) ) {
					throw new RuntimeException( 'اطلاعات محصول پس از پیش‌نمایش تغییر کرده است.' );
				}
				foreach ( $item['changes'] as $field => $change ) {
					if ( 'regular_price' === $field ) {
						$product->set_regular_price( $change['new'] );
					} elseif ( 'sale_price' === $field ) {
						$product->set_sale_price( $change['new'] );
					}
				}
				$product->save();
				wc_delete_product_transients( $id );
				if ( $product->get_parent_id() ) {
					$parents[ $product->get_parent_id() ] = true;
				}
				++$updated;
			} catch ( Throwable $e ) {
				++$skipped;
				$errors[] = 'ID ' . $id . ': ' . $e->getMessage();
			}
		}
		self::sync_parents( array_keys( $parents ) );
		delete_transient( self::bulk_preview_key( $token ) );
		delete_option( $lock_key );
		$message = sprintf( 'عملیات پایان یافت: %d محصول/تنوع به‌روزرسانی شد و %d مورد رد شد.', $updated, $skipped );
		if ( $errors ) {
			$message .= ' ' . implode( ' | ', array_slice( $errors, 0, 10 ) );
		}
		self::redirect_notice( 'bulk', $errors ? 'warning' : 'success', $message );
	}

	public static function handle_inline_save() {
		self::assert_access();
		check_admin_referer( 'wem_inline_price_save' );
		$category_id      = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$include_children = ! empty( $_POST['include_children'] );
		$page             = max( 1, isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1 );
		$ignore_stock     = ! empty( $_POST['ignore_stock'] );
		$rows             = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : array();
		$originals        = isset( $_POST['original'] ) && is_array( $_POST['original'] ) ? wp_unslash( $_POST['original'] ) : array();

		if ( count( $rows ) > self::MAX_FORM_ROWS ) {
			self::redirect_editor_notice( 'error', 'تعداد ردیف‌های فرم بیش از حد مجاز است.', $category_id, $include_children, $page );
		}

		$updated = 0;
		$skipped = 0;
		$errors  = array();
		$parents = array();

		foreach ( $rows as $id_raw => $values ) {
			$id = absint( $id_raw );
			if ( ! $id || ! isset( $originals[ $id_raw ] ) || ! is_array( $values ) || ! is_array( $originals[ $id_raw ] ) ) {
				continue;
			}
			$original = $originals[ $id_raw ];
			$has_regular = array_key_exists( 'regular_price', $values );
			$has_sale    = array_key_exists( 'sale_price', $values );
			$has_stock   = array_key_exists( 'stock_quantity', $values );
			$has_status  = array_key_exists( 'status', $values );
			$new_regular = $has_regular ? self::parse_decimal_or_blank( $values['regular_price'] ) : null;
			$new_sale    = $has_sale ? self::parse_decimal_or_blank( $values['sale_price'] ) : null;
			$new_stock   = $has_stock ? self::parse_decimal_or_blank( $values['stock_quantity'] ) : null;
			$new_status  = $has_status ? self::parse_status( $values['status'] ) : null;
			$old_regular = isset( $original['regular_price'] ) ? self::canonical_decimal( $original['regular_price'] ) : '';
			$old_sale    = isset( $original['sale_price'] ) ? self::canonical_decimal( $original['sale_price'] ) : '';
			$old_stock   = isset( $original['stock_quantity'] ) ? self::canonical_decimal( $original['stock_quantity'] ) : '';
			$old_status  = isset( $original['status'] ) ? sanitize_key( (string) $original['status'] ) : '';

			if ( ( $has_regular && null === $new_regular ) || ( $has_sale && null === $new_sale ) || ( $has_stock && ! $ignore_stock && null === $new_stock ) ) {
				++$skipped;
				$errors[] = 'ID ' . $id . ': قیمت و موجودی باید عدد معتبر یا سلول خالی باشند.';
				continue;
			}
			if ( $has_status && null === $new_status && sanitize_key( (string) $values['status'] ) !== $old_status ) {
				++$skipped;
				$errors[] = 'ID ' . $id . ': وضعیت فقط می‌تواند منتشر شده یا پیش‌نویس باشد.';
				continue;
			}

			$price_changed  = $has_regular && ! self::decimal_equal( $new_regular, $old_regular );
			$sale_changed   = $has_sale && ! self::decimal_equal( $new_sale, $old_sale );
			// در جدول نیز مانند ورود اکسل، موجودی خالی یعنی «بدون تغییر»؛
			// این رفتار از صفرشدن ناخواسته موجودی جلوگیری می‌کند.
			$stock_changed  = ! $ignore_stock && $has_stock && '' !== $new_stock && ! self::decimal_equal( $new_stock, $old_stock );
			$status_changed = null !== $new_status && $new_status !== $old_status;
			if ( ! $price_changed && ! $sale_changed && ! $stock_changed && ! $status_changed ) {
				continue;
			}

			try {
				$product = wc_get_product( $id );
				if ( ! $product || ! current_user_can( 'edit_post', $id ) ) {
					throw new RuntimeException( 'محصول پیدا نشد یا اجازه ویرایش ندارید.' );
				}
				$modified = $product->get_date_modified( 'edit' );
				$current_ts = $modified ? (int) $modified->getTimestamp() : 0;
				$expected_ts = isset( $original['modified_ts'] ) ? (int) $original['modified_ts'] : -1;
				if ( $current_ts !== $expected_ts ) {
					throw new RuntimeException( 'محصول پس از بارگذاری این صفحه تغییر کرده است؛ صفحه را تازه‌سازی کنید.' );
				}
				if ( $product->is_type( 'variable' ) && ( $price_changed || $sale_changed ) ) {
					throw new RuntimeException( 'قیمت محصول متغیر والد از روی تنوع‌ها محاسبه می‌شود.' );
				}
				if ( $product->is_type( 'variation' ) && $status_changed ) {
					throw new RuntimeException( 'وضعیت تنوع از این جدول قابل تغییر نیست.' );
				}
				if ( $stock_changed && ( null === $new_stock || (float) $new_stock < 0 ) ) {
					throw new RuntimeException( 'موجودی باید عددی صفر یا بیشتر باشد.' );
				}

				$effective_regular = $price_changed ? $new_regular : (string) $product->get_regular_price( 'edit' );
				$effective_sale    = $sale_changed ? $new_sale : (string) $product->get_sale_price( 'edit' );
				if ( '' !== $effective_sale && '' !== $effective_regular && (float) $effective_sale >= (float) $effective_regular ) {
					throw new RuntimeException( 'قیمت حراج باید کمتر از قیمت عادی باشد.' );
				}

				if ( $price_changed ) {
					$product->set_regular_price( $new_regular );
				}
				if ( $sale_changed ) {
					$product->set_sale_price( $new_sale );
				}
				if ( $stock_changed ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( (float) $new_stock );
					$product->set_stock_status( (float) $new_stock > 0 ? 'instock' : ( $product->backorders_allowed() ? 'onbackorder' : 'outofstock' ) );
				}
				if ( $status_changed ) {
					$product->set_status( $new_status );
				}
				$product->save();
				wc_delete_product_transients( $id );
				if ( $product->get_parent_id() ) {
					$parents[ $product->get_parent_id() ] = true;
				}
				++$updated;
			} catch ( Throwable $e ) {
				++$skipped;
				$errors[] = 'ID ' . $id . ': ' . $e->getMessage();
			}
		}

		self::sync_parents( array_keys( $parents ) );
		$message = sprintf( '%d ردیف واقعاً تغییرکرده ذخیره شد و %d ردیف رد شد.', $updated, $skipped );
		if ( $errors ) {
			$message .= ' ' . implode( ' | ', array_slice( $errors, 0, 10 ) );
		}
		self::redirect_editor_notice( $errors ? 'warning' : 'success', $message, $category_id, $include_children, $page );
	}

	private static function render_bulk_preview( $token ) {
		if ( ! $token ) {
			return;
		}
		$data = get_transient( self::bulk_preview_key( $token ) );
		if ( ! is_array( $data ) || (int) $data['user_id'] !== get_current_user_id() ) {
			echo '<div class="wem-card"><p class="wem-error">پیش‌نمایش منقضی یا نامعتبر است.</p></div>';
			return;
		}
		$changes = isset( $data['changes'] ) ? $data['changes'] : array();
		$reports = isset( $data['reports'] ) ? $data['reports'] : array();
		$errors  = isset( $data['errors'] ) ? $data['errors'] : array();
		$settings = isset( $data['settings'] ) ? $data['settings'] : array();
		?>
		<div class="wem-card">
			<h2>پیش‌نمایش قانون قیمت</h2>
			<div class="wem-summary"><span class="wem-badge">قابل تغییر: <strong><?php echo esc_html( count( $changes ) ); ?></strong></span><span class="wem-badge">خطا: <strong><?php echo esc_html( count( $errors ) ); ?></strong></span><span class="wem-badge">درصد: <strong><?php echo esc_html( isset( $settings['percentage'] ) ? $settings['percentage'] : '' ); ?>٪</strong></span></div>
			<?php if ( $errors ) : ?>
				<div class="notice notice-error inline"><p>تا رفع خطاها اعمال نهایی غیرفعال است.</p></div>
				<table class="wem-table"><thead><tr><th>ID</th><th>خطا</th></tr></thead><tbody><?php foreach ( array_slice( $errors, 0, 200 ) as $error ) : ?><tr><td><?php echo esc_html( $error['id'] ); ?></td><td><?php echo esc_html( $error['message'] ); ?></td></tr><?php endforeach; ?></tbody></table>
			<?php elseif ( ! $changes ) : ?>
				<p>هیچ قیمت قابل تغییری پیدا نشد.</p>
			<?php else : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('آیا از اعمال نهایی این تغییرات مطمئن هستید؟');"><input type="hidden" name="action" value="wem_bulk_price_apply"><input type="hidden" name="preview_token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'wem_bulk_price_apply' ); ?><p><button class="button button-primary button-hero" type="submit">تأیید و اعمال تغییرات درصدی</button></p></form>
				<table class="wem-table"><thead><tr><th>ID</th><th>SKU</th><th>محصول / تنوع</th><th>دسته‌بندی</th><th>تغییرات</th></tr></thead><tbody>
				<?php foreach ( array_slice( $reports, 0, 1000 ) as $report ) : ?><tr><td><?php echo esc_html( $report['id'] ); ?></td><td><?php echo esc_html( $report['sku'] ); ?></td><td><?php echo esc_html( $report['name'] ); ?></td><td><?php echo esc_html( $report['categories'] ); ?></td><td><?php foreach ( $report['changes'] as $field => $change ) : ?><div><strong><?php echo esc_html( 'regular_price' === $field ? 'قیمت عادی' : 'قیمت حراج' ); ?>:</strong> <?php echo esc_html( self::format_number( $change['old'] ) ); ?> ← <strong><?php echo esc_html( self::format_number( $change['new'] ) ); ?></strong></div><?php endforeach; ?></td></tr><?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_editor_table( $category_id, $include_children, $page ) {
		$query = self::paged_category_products( $category_id, $include_children, $page );
		$products = $query['products'];
		$total_pages = max( 1, $query['pages'] );
		if ( empty( $products ) ) {
			echo '<div class="wem-card"><p>محصولی در این دسته‌بندی پیدا نشد.</p></div>';
			return;
		}
		?>
		<div class="wem-card">
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('فقط ردیف‌هایی که تغییر داده‌اید ذخیره می‌شوند. ادامه می‌دهید؟');">
				<input type="hidden" name="action" value="wem_inline_price_save"><input type="hidden" name="category_id" value="<?php echo esc_attr( $category_id ); ?>"><input type="hidden" name="include_children" value="<?php echo $include_children ? '1' : '0'; ?>"><input type="hidden" name="paged" value="<?php echo esc_attr( $page ); ?>"><?php wp_nonce_field( 'wem_inline_price_save' ); ?>
				<div class="wem-actions"><label><input type="checkbox" name="ignore_stock" value="1"> موجودی‌ها در این ذخیره کاملاً بی‌تأثیر باشند</label><button class="button button-primary" type="submit">ذخیره ردیف‌های تغییرکرده</button></div>
				<div class="wem-scroll"><table class="wem-table wem-editor"><thead><tr><th>ID</th><th>نوع</th><th>SKU</th><th>محصول / تنوع</th><th>دسته‌بندی</th><th>وضعیت</th><th>قیمت عادی</th><th>قیمت حراج</th><th>موجودی</th></tr></thead><tbody>
				<?php foreach ( $products as $product ) : self::render_editor_row( $product, $product->is_type( 'variation' ) ); endforeach; ?>
				</tbody></table></div>
				<p><button class="button button-primary button-hero" type="submit">ذخیره ردیف‌های تغییرکرده</button></p>
			</form>
			<?php
			$base = add_query_arg( array( 'post_type' => 'product', 'page' => self::EDITOR_PAGE_SLUG, 'category_id' => $category_id, 'include_children' => $include_children ? 1 : 0, 'paged' => '%#%' ), admin_url( 'edit.php' ) );
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages, 'prev_text' => '«', 'next_text' => '»' ) ) ) . '</div></div>';
			?>
		</div>
		<?php
	}

	private static function render_editor_row( $product, $is_variation ) {
		$id          = $product->get_id();
		$regular     = (string) $product->get_regular_price( 'edit' );
		$sale        = (string) $product->get_sale_price( 'edit' );
		$stock       = $product->get_manage_stock() && null !== $product->get_stock_quantity( 'edit' ) ? (string) $product->get_stock_quantity( 'edit' ) : '';
		$status      = $is_variation ? '' : (string) $product->get_status( 'edit' );
		$modified    = $product->get_date_modified( 'edit' );
		$modified_ts = $modified ? (int) $modified->getTimestamp() : 0;
		$type_label  = $is_variation ? 'تنوع' : ( $product->is_type( 'variable' ) ? 'متغیر' : 'ساده' );
		?>
		<tr class="<?php echo $is_variation ? 'wem-variation-row' : 'wem-parent-row'; ?>">
			<td>
				<?php echo esc_html( $id ); ?>
				<input type="hidden" name="original[<?php echo esc_attr( $id ); ?>][regular_price]" value="<?php echo esc_attr( $regular ); ?>">
				<input type="hidden" name="original[<?php echo esc_attr( $id ); ?>][sale_price]" value="<?php echo esc_attr( $sale ); ?>">
				<input type="hidden" name="original[<?php echo esc_attr( $id ); ?>][stock_quantity]" value="<?php echo esc_attr( $stock ); ?>">
				<input type="hidden" name="original[<?php echo esc_attr( $id ); ?>][status]" value="<?php echo esc_attr( $status ); ?>">
				<input type="hidden" name="original[<?php echo esc_attr( $id ); ?>][modified_ts]" value="<?php echo esc_attr( $modified_ts ); ?>">
			</td>
			<td><?php echo esc_html( $type_label ); ?></td>
			<td><?php echo esc_html( $product->get_sku( 'edit' ) ); ?></td>
			<td><?php echo esc_html( self::display_name( $product ) ); ?></td>
			<td><?php echo esc_html( self::category_names( $product ) ); ?></td>
			<td>
				<?php if ( $is_variation ) : ?>
					<span class="wem-muted">تابع والد</span>
				<?php else : ?>
					<select name="products[<?php echo esc_attr( $id ); ?>][status]">
						<?php if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) : ?><option value="<?php echo esc_attr( $status ); ?>" selected>وضعیت فعلی: <?php echo esc_html( $status ); ?></option><?php endif; ?>
						<option value="publish" <?php selected( $status, 'publish' ); ?>>منتشر شده</option>
						<option value="draft" <?php selected( $status, 'draft' ); ?>>پیش‌نویس</option>
					</select>
				<?php endif; ?>
			</td>
			<td><?php if ( $product->is_type( 'variable' ) ) : ?><span class="wem-muted">از تنوع‌ها</span><?php else : ?><input class="wem-price-input" inputmode="decimal" name="products[<?php echo esc_attr( $id ); ?>][regular_price]" value="<?php echo esc_attr( $regular ); ?>"><?php endif; ?></td>
			<td><?php if ( $product->is_type( 'variable' ) ) : ?><span class="wem-muted">از تنوع‌ها</span><?php else : ?><input class="wem-price-input" inputmode="decimal" name="products[<?php echo esc_attr( $id ); ?>][sale_price]" value="<?php echo esc_attr( $sale ); ?>"><?php endif; ?></td>
			<td><input class="wem-price-input" inputmode="decimal" name="products[<?php echo esc_attr( $id ); ?>][stock_quantity]" value="<?php echo esc_attr( $stock ); ?>" placeholder="بدون مدیریت"></td>
		</tr>
		<?php
	}


	private static function paged_category_products( $category_id, $include_children, $page ) {
		$all = self::products_for_category( $category_id, $include_children, true );
		$total = count( $all );
		$pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page  = min( max( 1, (int) $page ), $pages );
		return array(
			'products' => array_slice( $all, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ),
			'pages'    => $pages,
			'total'    => $total,
		);
	}

	private static function products_for_category( $category_id, $include_children, $include_variations ) {
		$slugs = self::category_slugs( $category_id, $include_children );
		$products = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'category' => $slugs, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects' ) );
		$result = array();
		$seen = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product || isset( $seen[ $product->get_id() ] ) ) {
				continue;
			}
			$seen[ $product->get_id() ] = true;
			$result[] = $product;
			if ( $include_variations && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					if ( isset( $seen[ $child_id ] ) ) {
						continue;
					}
					$variation = wc_get_product( $child_id );
					if ( $variation ) {
						$seen[ $child_id ] = true;
						$result[] = $variation;
					}
				}
			}
		}
		return $result;
	}

	private static function category_slugs( $category_id, $include_children ) {
		$ids = array( $category_id );
		if ( $include_children ) {
			$children = get_term_children( $category_id, 'product_cat' );
			if ( ! is_wp_error( $children ) ) {
				$ids = array_merge( $ids, array_map( 'absint', $children ) );
			}
		}
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'include' => array_values( array_unique( $ids ) ), 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			throw new RuntimeException( $terms->get_error_message() );
		}
		return wp_list_pluck( $terms, 'slug' );
	}

	private static function category_dropdown( $name, $selected, $show_all ) {
		wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'hierarchical' => true, 'show_option_all' => $show_all ? '— انتخاب دسته‌بندی —' : '', 'name' => $name, 'id' => 'wem_category', 'selected' => $selected, 'orderby' => 'name', 'value_field' => 'term_id' ) );
	}

	private static function category_names( $product ) {
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$terms = get_the_terms( $id, 'product_cat' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return implode( '، ', wp_list_pluck( $terms, 'name' ) );
	}

	private static function display_name( $product ) {
		if ( ! $product->is_type( 'variation' ) ) {
			return (string) $product->get_name( 'edit' );
		}
		$parent = wc_get_product( $product->get_parent_id() );
		$base = $parent ? $parent->get_name( 'edit' ) : $product->get_name( 'edit' );
		$attributes = array();
		foreach ( $product->get_attributes() as $key => $value ) {
			$label = wc_attribute_label( str_replace( 'attribute_', '', $key ), $parent );
			$attributes[] = $label . ': ' . $value;
		}
		return $base . ( $attributes ? ' — ' . implode( ' | ', $attributes ) : ' — تنوع #' . $product->get_id() );
	}

	private static function price_snapshot( $product ) {
		$modified = $product->get_date_modified( 'edit' );
		return array( 'regular_price' => (string) $product->get_regular_price( 'edit' ), 'sale_price' => (string) $product->get_sale_price( 'edit' ), 'modified_ts' => $modified ? (int) $modified->getTimestamp() : 0 );
	}

	private static function price_snapshot_matches( $a, $b ) {
		return isset( $a['modified_ts'], $b['modified_ts'] ) && (int) $a['modified_ts'] === (int) $b['modified_ts'] && self::decimal_equal( $a['regular_price'], $b['regular_price'] ) && self::decimal_equal( $a['sale_price'], $b['sale_price'] );
	}

	private static function calculate_price( $value, $factor, $round_to ) {
		$price = (float) self::canonical_decimal( $value ) * $factor;
		if ( $round_to > 1 ) {
			$price = round( $price / $round_to ) * $round_to;
		} else {
			$price = round( $price, 8 );
		}
		return self::canonical_decimal( (string) $price );
	}

	private static function sync_parents( $parent_ids ) {
		foreach ( array_unique( array_map( 'absint', $parent_ids ) ) as $parent_id ) {
			if ( ! $parent_id ) {
				continue;
			}
			try {
				WC_Product_Variable::sync( $parent_id );
				WC_Product_Variable::sync_stock_status( $parent_id );
				wc_delete_product_transients( $parent_id );
			} catch ( Throwable $e ) {
				// Individual child saves are already complete; do not roll them back.
			}
		}
	}

	private static function parse_decimal( $value ) {
		$value = self::normalize_digits( trim( (string) $value ) );
		$value = str_replace( array( ' ', "\xC2\xA0", '٬', ',' ), '', $value );
		$value = str_replace( '٫', '.', $value );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return null;
		}
		return (float) $value;
	}

	private static function parse_decimal_or_blank( $value ) {
		$value = self::normalize_digits( trim( (string) $value ) );
		$value = str_replace( array( ' ', "\xC2\xA0", '٬', ',' ), '', $value );
		$value = str_replace( '٫', '.', $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return null;
		}
		return self::canonical_decimal( $value );
	}

	private static function canonical_decimal( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false !== strpos( $value, '.' ) ) {
			$value = rtrim( rtrim( $value, '0' ), '.' );
		}
		$value = ltrim( $value, '0' );
		return '' === $value ? '0' : ( 0 === strpos( $value, '.' ) ? '0' . $value : $value );
	}

	private static function decimal_equal( $a, $b ) {
		return self::canonical_decimal( $a ) === self::canonical_decimal( $b );
	}

	private static function normalize_digits( $value ) {
		return strtr( (string) $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) );
	}

	private static function normalize_status( $status ) {
		return 'draft' === $status ? 'draft' : 'publish';
	}

	private static function parse_status( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'publish', 'published', 'منتشر', 'منتشر شده', 'منتشرشده' ), true ) ) {
			return 'publish';
		}
		if ( in_array( $value, array( 'draft', 'پیش نویس', 'پیش‌نویس', 'پیشنویس' ), true ) ) {
			return 'draft';
		}
		return null;
	}

	private static function format_number( $value ) {
		return '' === (string) $value ? '—' : number_format_i18n( (float) $value, floor( (float) $value ) == (float) $value ? 0 : 2 );
	}

	private static function bulk_preview_key( $token ) {
		return 'wem_bulk_preview_' . get_current_user_id() . '_' . md5( $token );
	}

	private static function render_styles() {
		echo '<style>.wem-wrap{max-width:1500px}.wem-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:18px 0}.wem-help{color:#50575e;line-height:1.9}.wem-actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.wem-table{width:100%;border-collapse:collapse;margin-top:14px}.wem-table th,.wem-table td{border:1px solid #dcdcde;padding:8px;text-align:right;vertical-align:middle}.wem-table th{background:#f6f7f7}.wem-summary{display:flex;gap:10px;flex-wrap:wrap}.wem-badge{background:#f0f0f1;border-radius:999px;padding:7px 12px}.wem-error{color:#b32d2e;font-weight:700}.wem-muted{color:#646970}.wem-scroll{overflow:auto}.wem-editor{min-width:1150px}.wem-price-input{width:150px}.wem-variation-row td{background:#fbfbfc}.wem-variation-row td:nth-child(4){padding-right:28px}.wem-parent-row td{font-weight:600}</style>';
	}

	private static function render_notice( $notice ) {
		if ( is_array( $notice ) ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	private static function notice_key( $scope ) {
		return 'wem_' . $scope . '_notice_' . get_current_user_id();
	}

	private static function pull_notice( $scope ) {
		$key = self::notice_key( $scope );
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
		}
		return $notice;
	}

	private static function redirect_notice( $scope, $type, $message ) {
		set_transient( self::notice_key( $scope ), array( 'type' => $type, 'message' => $message ), 180 );
		$page = 'bulk' === $scope ? self::BULK_PAGE_SLUG : self::EDITOR_PAGE_SLUG;
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'product', 'page' => $page ), admin_url( 'edit.php' ) ) );
		exit;
	}

	private static function redirect_editor_notice( $type, $message, $category_id, $include_children, $page ) {
		set_transient( self::notice_key( 'editor' ), array( 'type' => $type, 'message' => $message ), 180 );
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'product', 'page' => self::EDITOR_PAGE_SLUG, 'category_id' => $category_id, 'include_children' => $include_children ? 1 : 0, 'paged' => $page ), admin_url( 'edit.php' ) ) );
		exit;
	}

	private static function assert_access() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'شما اجازه مدیریت محصولات ووکامرس را ندارید.', '', array( 'response' => 403 ) );
		}
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_products' ) ) {
			wp_die( 'ووکامرس باید فعال باشد.' );
		}
	}
}
