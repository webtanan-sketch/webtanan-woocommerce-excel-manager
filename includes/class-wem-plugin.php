<?php
/**
 * Main plugin controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Plugin {
	const PAGE_SLUG       = 'webtanan-woocommerce-excel-manager';
	const CAPABILITY      = 'manage_woocommerce';
	const PREVIEW_TTL     = 1800;
	const MAX_UPLOAD_SIZE = 10485760; // 10 MB.
	const MAX_ROWS        = 20000;
	const MAX_CHANGES     = 10000;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		add_action( 'admin_post_wem_export_products', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_wem_preview_import', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_wem_apply_import', array( __CLASS__, 'handle_apply' ) );
		add_action( 'wem_cleanup_preview_file', array( __CLASS__, 'cleanup_preview_file' ) );
	}

	public static function admin_menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			'مدیریت اکسل محصولات',
			'اکسل محصولات',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$messages = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$messages[] = 'افزونه ووکامرس فعال نیست.';
		}
		$missing = WEM_XLSX::missing_requirements();
		if ( ! empty( $missing ) ) {
			$messages[] = 'افزونه‌های PHP موردنیاز روی سرور فعال نیستند: ' . implode( '، ', $missing );
		}

		if ( empty( $messages ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Webtanan WooCommerce Excel Manager:</strong> ' . esc_html( implode( ' ', $messages ) ) . '</p></div>';
	}

	public static function render_page() {
		self::assert_access();

		$preview_token = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : '';
		$result_key    = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
		$notice        = get_transient( self::notice_key() );
		if ( $notice ) {
			delete_transient( self::notice_key() );
		}

		?>
		<div class="wrap wem-wrap" dir="rtl">
			<h1>مدیریت اکسل محصولات ووکامرس</h1>
			<style>
				.wem-wrap{max-width:1280px}.wem-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:18px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)}
				.wem-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.wem-help{color:#50575e;line-height:1.9}.wem-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
				.wem-table{width:100%;border-collapse:collapse;margin-top:14px}.wem-table th,.wem-table td{border:1px solid #dcdcde;padding:9px;text-align:right;vertical-align:top}.wem-table th{background:#f6f7f7}
				.wem-ok{color:#008a20;font-weight:700}.wem-error{color:#b32d2e;font-weight:700}.wem-warning{color:#996800;font-weight:700}.wem-change{margin:0 0 6px}.wem-old{color:#646970}.wem-new{color:#135e96;font-weight:600}
				.wem-summary{display:flex;gap:12px;flex-wrap:wrap}.wem-badge{background:#f0f0f1;border-radius:999px;padding:7px 12px}.wem-file{padding:12px;background:#f6f7f7;border:1px dashed #8c8f94;border-radius:6px;width:100%;box-sizing:border-box}
				@media(max-width:900px){.wem-grid{grid-template-columns:1fr}}
			</style>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( isset( $notice['type'] ) ? $notice['type'] : 'info' ); ?> is-dismissible"><p><?php echo esc_html( isset( $notice['message'] ) ? $notice['message'] : '' ); ?></p></div>
			<?php endif; ?>

			<?php self::render_result( $result_key ); ?>

			<div class="wem-grid">
				<div class="wem-card">
					<h2>۱) دریافت فایل اکسل</h2>
					<p class="wem-help">فایل واقعی <code>.xlsx</code> شامل ID، SKU، عنوان، وضعیت، قیمت عادی، قیمت حراج، موجودی، دسته‌بندی، نوع محصول، ID والد و مشخصات تنوع است. قیمت حراج دقیقاً کنار قیمت عادی قرار می‌گیرد.</p>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="wem_export_products">
						<?php wp_nonce_field( 'wem_export_products' ); ?>
						<p><label><strong>دسته‌بندی:</strong> <?php self::render_category_dropdown( 'category_id', 0, true ); ?></label></p>
						<p><label><input type="checkbox" name="include_children" value="1" checked> زیر‌دسته‌ها نیز لحاظ شوند</label></p>
						<p><label><input type="checkbox" name="include_variations" value="1" checked> ردیف تمام تنوع‌های محصولات متغیر نیز خروجی گرفته شود</label></p>
						<p><button class="button button-primary button-hero" type="submit">دریافت فایل اکسل محصولات</button></p>
					</form>
				</div>

				<div class="wem-card">
					<h2>۲) آزمایش فایل و تهیه گزارش</h2>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
						<input type="hidden" name="action" value="wem_preview_import">
						<?php wp_nonce_field( 'wem_preview_import' ); ?>
						<p><input class="wem-file" type="file" name="wem_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></p>
						<p><label><input type="checkbox" name="ignore_stock" value="1"> ستون موجودی کاملاً بی‌تأثیر باشد؛ حتی اگر فایل قدیمی موجودی متفاوتی دارد</label></p>
						<p><label><input type="checkbox" name="dry_run" value="1" checked required> ابتدا فقط تست شود و گزارش تغییرات نمایش داده شود.</label></p>
						<p><button class="button button-primary" type="submit">بررسی و نمایش گزارش</button></p>
					</form>
				</div>
			</div>

			<div class="wem-card">
				<h2>قواعد ایمن ویرایش</h2>
				<ul class="wem-help">
					<li>ستون ID مرجع اصلی شناسایی محصول است و نباید تغییر کند.</li>
					<li>خالی‌کردن قیمت عادی یا قیمت حراج، همان قیمت را پاک می‌کند؛ قیمت حراج باید کمتر از قیمت عادی باشد.</li>
					<li>ستون وضعیت فقط مقادیر «منتشر شده / publish» و «پیش‌نویس / draft» را می‌پذیرد.</li>
					<li>با تیک «موجودی بی‌تأثیر»، تمام اختلاف‌های موجودی فایل نادیده گرفته می‌شوند.</li>
					<li>واردکردن عدد در موجودی، مدیریت موجودی آن محصول را فعال می‌کند.</li>
					<li>برای محصول متغیر، قیمت و موجودی هر تنوع را در ردیف همان تنوع ویرایش کنید. قیمت مستقیم ردیف والد قابل ویرایش نیست.</li>
					<li>فایل‌های دارای فرمول، ماکرو، شیء جاسازی‌شده یا لینک خارجی پردازش نمی‌شوند.</li>
					<li>متن فارسی، UTF-8 و BOM در ابتدای متن یا عنوان ستون‌ها پشتیبانی و پاک‌سازی می‌شود.</li>
				</ul>
			</div>

			<?php self::render_preview( $preview_token ); ?>
		</div>
		<?php
	}

	public static function handle_export() {
		self::assert_access();
		check_admin_referer( 'wem_export_products' );
		self::assert_runtime_requirements();

		$category_id        = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$include_children   = ! empty( $_POST['include_children'] );
		$include_variations = ! empty( $_POST['include_variations'] );

		if ( $category_id && ! term_exists( $category_id, 'product_cat' ) ) {
			wp_die( esc_html__( 'دسته‌بندی انتخاب‌شده معتبر نیست.', 'webtanan-woocommerce-excel-manager' ) );
		}

		$tmp = wp_tempnam( 'webtanan-products.xlsx' );
		if ( ! $tmp ) {
			wp_die( esc_html__( 'امکان ساخت فایل موقت وجود ندارد.', 'webtanan-woocommerce-excel-manager' ) );
		}

		try {
			WEM_XLSX::write( $tmp, self::export_rows( $category_id, $include_children, $include_variations ) );

			$filename = 'woocommerce-products-' . gmdate( 'Y-m-d-His' ) . '.xlsx';
			nocache_headers();
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . (string) filesize( $tmp ) );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $tmp );
		} catch ( Throwable $e ) {
			@unlink( $tmp );
			wp_die( esc_html( 'خطا در ساخت فایل اکسل: ' . $e->getMessage() ) );
		}

		@unlink( $tmp );
		exit;
	}

	public static function handle_preview() {
		self::assert_access();
		check_admin_referer( 'wem_preview_import' );
		self::assert_runtime_requirements();

		if ( empty( $_POST['dry_run'] ) ) {
			self::redirect_notice( 'error', 'برای امنیت، مرحله تست و گزارش الزامی است.' );
		}
		if ( empty( $_FILES['wem_file'] ) || ! is_array( $_FILES['wem_file'] ) ) {
			self::redirect_notice( 'error', 'فایل اکسل دریافت نشد.' );
		}

		$file = $_FILES['wem_file'];
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::redirect_notice( 'error', self::upload_error_message( (int) $file['error'] ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect_notice( 'error', 'آپلود فایل معتبر نیست.' );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_UPLOAD_SIZE ) {
			self::redirect_notice( 'error', 'حجم فایل باید بیشتر از صفر و حداکثر ۱۰ مگابایت باشد.' );
		}

		$name      = sanitize_file_name( wp_unslash( $file['name'] ) );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'xlsx' !== $extension ) {
			self::redirect_notice( 'error', 'فقط فایل با پسوند واقعی XLSX پذیرفته می‌شود.' );
		}

		$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
		$mime  = $finfo ? finfo_file( $finfo, $file['tmp_name'] ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}
		$allowed_mimes = array(
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/zip',
			'application/octet-stream',
			'application/x-zip-compressed',
			'application/x-zip',
		);
		if ( $mime && ! in_array( $mime, $allowed_mimes, true ) ) {
			self::redirect_notice( 'error', 'نوع واقعی فایل با XLSX سازگار نیست.' );
		}

		try {
			$file_hash = hash_file( 'sha256', $file['tmp_name'] );
			if ( false === $file_hash ) {
				throw new RuntimeException( 'محاسبه اثر انگشت فایل ممکن نشد.' );
			}
			$ignore_stock = ! empty( $_POST['ignore_stock'] );
			$preview = self::build_preview( $file['tmp_name'], $file_hash, $ignore_stock );
			$token   = strtolower( wp_generate_password( 24, false, false ) );
			$path    = wp_tempnam( 'wem-preview-' . $token . '.json' );

			if ( ! $path ) {
				throw new RuntimeException( 'امکان ساخت فایل امن پیش‌نمایش وجود ندارد.' );
			}

			$payload = wp_json_encode( $preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $payload || false === file_put_contents( $path, $payload, LOCK_EX ) ) {
				@unlink( $path );
				throw new RuntimeException( 'امکان ذخیره گزارش آزمایشی وجود ندارد.' );
			}
			@chmod( $path, 0600 );

			set_transient(
				self::preview_key( $token ),
				array(
					'path'       => $path,
					'created_at' => time(),
					'file_hash'  => $preview['file_hash'],
				),
				self::PREVIEW_TTL
			);

			wp_schedule_single_event( time() + self::PREVIEW_TTL + 120, 'wem_cleanup_preview_file', array( $path ) );
			wp_safe_redirect( add_query_arg( array( 'post_type' => 'product', 'page' => self::PAGE_SLUG, 'preview' => $token ), admin_url( 'edit.php' ) ) );
			exit;
		} catch ( Throwable $e ) {
			self::redirect_notice( 'error', 'خطا در بررسی فایل: ' . $e->getMessage() );
		}
	}

	public static function handle_apply() {
		self::assert_access();
		check_admin_referer( 'wem_apply_import' );
		self::assert_runtime_requirements();

		$token = isset( $_POST['preview_token'] ) ? sanitize_key( wp_unslash( $_POST['preview_token'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9]{20,40}$/', $token ) ) {
			self::redirect_notice( 'error', 'شناسه پیش‌نمایش نامعتبر است.' );
		}

		$meta = get_transient( self::preview_key( $token ) );
		if ( ! is_array( $meta ) || empty( $meta['path'] ) || ! is_readable( $meta['path'] ) ) {
			self::redirect_notice( 'error', 'گزارش آزمایشی منقضی شده یا در دسترس نیست. فایل را دوباره بررسی کنید.' );
		}

		$lock_key      = 'wem_apply_lock_' . md5( get_current_user_id() . '|' . $token );
		$existing_lock = (int) get_option( $lock_key, 0 );
		if ( $existing_lock && ( time() - $existing_lock ) > 15 * MINUTE_IN_SECONDS ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			self::redirect_notice( 'error', 'این عملیات هم‌اکنون در حال اجراست یا قبلاً اجرا شده است.' );
		}

		try {
			$data = json_decode( file_get_contents( $meta['path'] ), true );
			if ( ! is_array( $data ) || empty( $data['changes'] ) || ! empty( $data['errors'] ) ) {
				throw new RuntimeException( 'گزارش آزمایشی برای اعمال نهایی معتبر نیست.' );
			}
			if ( empty( $data['user_id'] ) || (int) $data['user_id'] !== get_current_user_id() ) {
				throw new RuntimeException( 'این گزارش آزمایشی متعلق به کاربر فعلی نیست.' );
			}
			if ( empty( $data['file_hash'] ) || ! hash_equals( (string) $meta['file_hash'], (string) $data['file_hash'] ) ) {
				throw new RuntimeException( 'یکپارچگی گزارش آزمایشی تأیید نشد.' );
			}

			$result = self::apply_changes( $data['changes'] );
			set_transient( self::result_key( $token ), $result, self::PREVIEW_TTL );

			delete_transient( self::preview_key( $token ) );
			@unlink( $meta['path'] );
			delete_option( $lock_key );

			wp_safe_redirect( add_query_arg( array( 'post_type' => 'product', 'page' => self::PAGE_SLUG, 'result' => $token ), admin_url( 'edit.php' ) ) );
			exit;
		} catch ( Throwable $e ) {
			delete_option( $lock_key );
			self::redirect_notice( 'error', 'خطا در اعمال نهایی: ' . $e->getMessage() );
		}
	}

	public static function cleanup_preview_file( $path ) {
		$base = basename( (string) $path );
		if ( 0 === strpos( $base, 'wem-preview-' ) && is_file( $path ) ) {
			@unlink( $path );
		}
	}

	private static function render_preview( $token ) {
		if ( ! $token ) {
			return;
		}

		$meta = get_transient( self::preview_key( $token ) );
		if ( ! is_array( $meta ) || empty( $meta['path'] ) || ! is_readable( $meta['path'] ) ) {
			echo '<div class="wem-card"><p class="wem-error">گزارش آزمایشی منقضی شده است. فایل را دوباره بارگذاری کنید.</p></div>';
			return;
		}

		$data = json_decode( file_get_contents( $meta['path'] ), true );
		if ( ! is_array( $data ) ) {
			echo '<div class="wem-card"><p class="wem-error">گزارش آزمایشی قابل خواندن نیست.</p></div>';
			return;
		}

		$summary = isset( $data['summary'] ) ? $data['summary'] : array();
		$reports = isset( $data['reports'] ) ? $data['reports'] : array();
		$errors  = isset( $data['errors'] ) ? $data['errors'] : array();
		?>
		<div class="wem-card">
			<h2>گزارش آزمایشی فایل</h2>
			<div class="wem-summary">
				<span class="wem-badge">ردیف‌های داده: <strong><?php echo esc_html( (string) ( isset( $summary['rows'] ) ? $summary['rows'] : 0 ) ); ?></strong></span>
				<span class="wem-badge">محصولات قابل ویرایش: <strong><?php echo esc_html( (string) ( isset( $summary['changes'] ) ? $summary['changes'] : 0 ) ); ?></strong></span>
				<span class="wem-badge">بدون تغییر: <strong><?php echo esc_html( (string) ( isset( $summary['unchanged'] ) ? $summary['unchanged'] : 0 ) ); ?></strong></span>
				<span class="wem-badge">خطاها: <strong><?php echo esc_html( (string) count( $errors ) ); ?></strong></span>
					<?php if ( ! empty( $data['ignore_stock'] ) ) : ?><span class="wem-badge">موجودی: <strong>کاملاً بی‌تأثیر</strong></span><?php endif; ?>
			</div>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error inline"><p><strong>تا زمان رفع همه خطاها، تأیید نهایی غیرفعال است.</strong> فایل را اصلاح و دوباره بارگذاری کنید.</p></div>
			<?php elseif ( empty( $data['changes'] ) ) : ?>
				<div class="notice notice-info inline"><p>هیچ تغییری نسبت به اطلاعات فعلی سرور پیدا نشد.</p></div>
			<?php else : ?>
				<div class="notice notice-warning inline"><p>پس از تأیید، فقط محصولات زیر با همان مقادیر آزمایش‌شده به‌روزرسانی می‌شوند. اگر محصولی بعد از تست روی سرور تغییر کرده باشد، برای جلوگیری از بازنویسی ناخواسته رد می‌شود.</p></div>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('آیا از اعمال نهایی تغییرات روی محصولات گزارش‌شده مطمئن هستید؟');">
					<input type="hidden" name="action" value="wem_apply_import">
					<input type="hidden" name="preview_token" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( 'wem_apply_import' ); ?>
					<p><button type="submit" class="button button-primary button-hero">تأیید نهایی و اعمال تغییرات</button></p>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $reports ) ) : ?>
				<table class="wem-table">
					<thead><tr><th>ردیف</th><th>ID</th><th>SKU</th><th>محصول</th><th>وضعیت و جزئیات</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $reports, 0, 1000 ) as $report ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $report['row'] ); ?></td>
							<td><?php echo esc_html( (string) $report['id'] ); ?></td>
							<td><?php echo esc_html( (string) $report['sku'] ); ?></td>
							<td><?php echo esc_html( (string) $report['name'] ); ?></td>
							<td><?php self::render_report_details( $report ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $reports ) > 1000 ) : ?><p class="wem-help">برای سبک‌ماندن صفحه، فقط ۱۰۰۰ مورد اول نمایش داده شده است؛ همه تغییرات معتبر در مرحله نهایی لحاظ می‌شوند.</p><?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_result( $token ) {
		if ( ! $token ) {
			return;
		}
		$result = get_transient( self::result_key( $token ) );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( self::result_key( $token ) );
		?>
		<div class="wem-card">
			<h2>نتیجه اعمال نهایی</h2>
			<div class="wem-summary">
				<span class="wem-badge">به‌روزرسانی موفق: <strong><?php echo esc_html( (string) $result['updated'] ); ?></strong></span>
				<span class="wem-badge">تداخل یا ردشده: <strong><?php echo esc_html( (string) $result['skipped'] ); ?></strong></span>
				<span class="wem-badge">خطای اجرا: <strong><?php echo esc_html( (string) count( $result['errors'] ) ); ?></strong></span>
			</div>
			<?php if ( ! empty( $result['errors'] ) ) : ?>
				<table class="wem-table"><thead><tr><th>ID</th><th>پیام</th></tr></thead><tbody>
				<?php foreach ( $result['errors'] as $error ) : ?>
					<tr><td><?php echo esc_html( (string) $error['id'] ); ?></td><td><?php echo esc_html( (string) $error['message'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php else : ?>
				<p class="wem-ok">عملیات نهایی بدون خطا پایان یافت.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_report_details( $report ) {
		if ( 'error' === $report['status'] ) {
			echo '<div class="wem-error">خطا</div>';
			foreach ( $report['errors'] as $error ) {
				echo '<div>' . esc_html( $error ) . '</div>';
			}
			return;
		}

		echo '<div class="wem-ok">آماده ویرایش</div>';
		foreach ( $report['changes'] as $label => $change ) {
			echo '<div class="wem-change"><strong>' . esc_html( self::field_label( $label ) ) . ':</strong> <span class="wem-old">' . esc_html( self::display_value( $change['old'] ) ) . '</span> ← <span class="wem-new">' . esc_html( self::display_value( $change['new'] ) ) . '</span></div>';
		}
	}

	private static function build_preview( $path, $file_hash, $ignore_stock = false ) {
		$header_map = null;
		$rows       = 0;
		$unchanged  = 0;
		$changes    = array();
		$reports    = array();
		$errors     = array();
		$seen_ids   = array();
		$seen_skus  = array();
		$header_row = 0;

		WEM_XLSX::read_rows(
			$path,
			function ( $row, $row_number ) use ( &$header_map, &$rows, &$unchanged, &$changes, &$reports, &$errors, &$seen_ids, &$seen_skus, &$header_row, $ignore_stock ) {
				if ( self::row_is_empty( $row ) ) {
					return;
				}

				if ( null === $header_map ) {
					$header_map = self::map_headers( $row );
					$header_row = $row_number;
					return;
				}

				++$rows;
				$row_errors = array();
				$id_raw     = self::cell( $row, $header_map['id'] );
				$id_string  = self::normalize_digits( trim( self::strip_bom( $id_raw ) ) );
				$id         = ctype_digit( $id_string ) ? (int) $id_string : 0;

				if ( $id <= 0 ) {
					$row_errors[] = 'ID باید یک عدد صحیح معتبر باشد.';
				}
				if ( $id > 0 && isset( $seen_ids[ $id ] ) ) {
					$row_errors[] = 'ID در فایل تکراری است؛ ردیف قبلی: ' . $seen_ids[ $id ];
				}
				if ( $id > 0 ) {
					$seen_ids[ $id ] = $row_number;
				}

				$product = $id > 0 ? wc_get_product( $id ) : false;
				if ( $product && ! current_user_can( 'edit_post', $id ) ) {
					$row_errors[] = 'شما اجازه ویرایش این محصول را ندارید.';
				}
				if ( $id > 0 && ! $product ) {
					$row_errors[] = 'محصولی با این ID روی سرور پیدا نشد.';
				}

				$report = array(
					'row'     => $row_number,
					'id'      => $id,
					'sku'     => '',
					'name'    => '',
					'status'  => 'error',
					'errors'  => array(),
					'changes' => array(),
				);

				if ( ! $product ) {
					$report['errors'] = $row_errors;
					$reports[]        = $report;
					$errors[]         = array( 'row' => $row_number, 'id' => $id, 'messages' => $row_errors );
					return;
				}

				$current        = self::snapshot( $product );
				$report['sku']  = $current['sku'];
				$report['name'] = $current['name'];

				$new_name = sanitize_text_field( self::strip_bom( self::cell( $row, $header_map['name'] ) ) );
				$new_sku  = sanitize_text_field( self::strip_bom( self::cell( $row, $header_map['sku'] ) ) );
				$new_sku  = trim( $new_sku );

				if ( self::text_equal( $new_name, $current['name'] ) ) {
					$new_name = $current['name'];
				}
				if ( self::sku_equal( $new_sku, $current['sku'] ) ) {
					$new_sku = $current['sku'];
				}
				if ( '' === trim( $new_name ) ) {
					$row_errors[] = 'عنوان محصول نمی‌تواند خالی باشد.';
				}

				$regular = self::parse_decimal( self::cell( $row, $header_map['regular_price'] ), true, 'قیمت عادی' );
				$sale    = self::parse_decimal( self::cell( $row, $header_map['sale_price'] ), true, 'قیمت حراج' );
				$stock   = $ignore_stock
					? array( 'ok' => true, 'value' => '', 'error' => '' )
					: self::parse_decimal( self::cell( $row, $header_map['stock_quantity'] ), true, 'موجودی' );

				if ( ! $regular['ok'] ) {
					$row_errors[] = $regular['error'];
				}
				if ( ! $sale['ok'] ) {
					$row_errors[] = $sale['error'];
				}
				if ( ! $stock['ok'] ) {
					$row_errors[] = $stock['error'];
				}
				if ( $stock['ok'] && '' !== $stock['value'] && (float) $stock['value'] < 0 ) {
					$row_errors[] = 'موجودی نمی‌تواند منفی باشد.';
				}
				if ( $regular['ok'] && '' !== $regular['value'] && (float) $regular['value'] < 0 ) {
					$row_errors[] = 'قیمت عادی نمی‌تواند منفی باشد.';
				}
				if ( $sale['ok'] && '' !== $sale['value'] && (float) $sale['value'] < 0 ) {
					$row_errors[] = 'قیمت حراج نمی‌تواند منفی باشد.';
				}

				$new_status = $current['status'];
				if ( isset( $header_map['status'] ) && ! $product->is_type( 'variation' ) ) {
					$status_raw = self::cell( $row, $header_map['status'] );
					$parsed_status = self::parse_product_status( $status_raw );
					if ( null === $parsed_status ) {
						$status_compare = self::lower( trim( self::normalize_text_for_compare( self::normalize_digits( $status_raw ) ) ) );
						if ( $status_compare === self::lower( (string) $current['status'] ) ) {
							$new_status = $current['status'];
						} else {
							$row_errors[] = 'وضعیت محصول فقط باید «منتشر شده / publish» یا «پیش‌نویس / draft» باشد.';
						}
					} else {
						$new_status = $parsed_status;
					}
				}

				if ( '' !== $new_sku ) {
					$sku_key = self::lower( self::normalize_text_for_compare( $new_sku ) );
					if ( isset( $seen_skus[ $sku_key ] ) && $seen_skus[ $sku_key ] !== $id ) {
						$row_errors[] = 'SKU در فایل برای چند محصول تکرار شده است.';
					} else {
						$seen_skus[ $sku_key ] = $id;
					}
					$existing_id = wc_get_product_id_by_sku( $new_sku );
					if ( $existing_id && (int) $existing_id !== $id ) {
						$row_errors[] = 'این SKU قبلاً برای محصول ID ' . (int) $existing_id . ' استفاده شده است.';
					}
				}

				$field_changes = array();
				if ( $new_name !== $current['name'] ) {
					if ( $product->is_type( 'variation' ) ) {
						$row_errors[] = 'عنوان تنوع محصول قابل ویرایش نیست؛ عنوان از محصول والد و ویژگی‌ها ساخته می‌شود.';
					} else {
						$field_changes['name'] = array( 'old' => $current['name'], 'new' => $new_name );
					}
				}
				if ( $new_sku !== $current['sku'] ) {
					$field_changes['sku'] = array( 'old' => $current['sku'], 'new' => $new_sku );
				}
				if ( $new_status !== $current['status'] ) {
					$field_changes['status'] = array( 'old' => $current['status'], 'new' => $new_status );
				}

				if ( $regular['ok'] && ! self::decimal_equal( $regular['value'], $current['regular_price'] ) ) {
					if ( $product->is_type( 'variable' ) ) {
						$row_errors[] = 'قیمت عادی محصول متغیر والد باید در ردیف تنوع‌ها تغییر کند.';
					} else {
						$field_changes['regular_price'] = array( 'old' => $current['regular_price'], 'new' => $regular['value'] );
					}
				}
				if ( $sale['ok'] && ! self::decimal_equal( $sale['value'], $current['sale_price'] ) ) {
					if ( $product->is_type( 'variable' ) ) {
						$row_errors[] = 'قیمت حراج محصول متغیر والد باید در ردیف تنوع‌ها تغییر کند.';
					} else {
						$field_changes['sale_price'] = array( 'old' => $current['sale_price'], 'new' => $sale['value'] );
					}
				}

				$effective_regular = isset( $field_changes['regular_price'] ) ? $field_changes['regular_price']['new'] : $current['regular_price'];
				$effective_sale    = isset( $field_changes['sale_price'] ) ? $field_changes['sale_price']['new'] : $current['sale_price'];
				if ( '' !== $effective_sale && '' !== $effective_regular && (float) $effective_sale >= (float) $effective_regular ) {
					$row_errors[] = 'قیمت حراج باید کمتر از قیمت عادی باشد.';
				}

				if ( ! $ignore_stock && $stock['ok'] && '' !== $stock['value'] ) {
					$current_stock = null === $current['stock_quantity'] ? '' : $current['stock_quantity'];
					if ( ! $current['manage_stock'] || ! self::decimal_equal( $stock['value'], $current_stock ) ) {
						$field_changes['stock_quantity'] = array( 'old' => $current_stock, 'new' => $stock['value'] );
					}
				}

				if ( ! empty( $row_errors ) ) {
					$report['errors'] = array_values( array_unique( $row_errors ) );
					$reports[]        = $report;
					$errors[]         = array( 'row' => $row_number, 'id' => $id, 'messages' => $report['errors'] );
					return;
				}

				if ( empty( $field_changes ) ) {
					++$unchanged;
					return;
				}

				if ( count( $changes ) >= self::MAX_CHANGES ) {
					throw new RuntimeException( 'تعداد تغییرات از حد مجاز ' . self::MAX_CHANGES . ' بیشتر است؛ فایل را به چند بخش تقسیم کنید.' );
				}

				$report['status']  = 'change';
				$report['changes'] = $field_changes;
				$reports[]         = $report;
				$changes[]         = array(
					'id'        => $id,
					'parent_id' => $current['parent_id'],
					'type'      => $current['type'],
					'snapshot'  => $current,
					'changes'   => $field_changes,
				);
			},
			self::MAX_ROWS + 1
		);

		if ( null === $header_map ) {
			throw new RuntimeException( 'ردیف عنوان ستون‌ها پیدا نشد.' );
		}
		if ( $header_row > 10 ) {
			throw new RuntimeException( 'ردیف عنوان ستون‌ها باید در ابتدای فایل باشد.' );
		}

		return array(
			'version'      => WEM_VERSION,
			'user_id'      => get_current_user_id(),
			'file_hash'    => (string) $file_hash,
			'created_at'   => time(),
			'ignore_stock' => (bool) $ignore_stock,
			'summary'      => array(
				'rows'      => $rows,
				'changes'   => count( $changes ),
				'unchanged' => $unchanged,
			),
			'changes'      => $changes,
			'reports'      => $reports,
			'errors'       => $errors,
		);
	}


	private static function apply_changes( $items ) {
		$result = array(
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
		$parents_to_sync = array();

		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			try {
				$product = $id ? wc_get_product( $id ) : false;
				if ( ! $product ) {
					throw new RuntimeException( 'محصول دیگر روی سرور وجود ندارد.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					throw new RuntimeException( 'اجازه ویرایش این محصول را ندارید.' );
				}

				$current = self::snapshot( $product );
				if ( ! self::snapshot_matches( $current, $item['snapshot'] ) ) {
					++$result['skipped'];
					$result['errors'][] = array( 'id' => $id, 'message' => 'از زمان تست، اطلاعات این محصول روی سرور تغییر کرده است؛ برای جلوگیری از بازنویسی رد شد.' );
					continue;
				}

				foreach ( $item['changes'] as $field => $change ) {
					$new_value = isset( $change['new'] ) ? $change['new'] : '';
					switch ( $field ) {
						case 'name':
							$product->set_name( $new_value );
							break;
						case 'sku':
							$product->set_sku( $new_value );
							break;
						case 'regular_price':
							$product->set_regular_price( $new_value );
							break;
						case 'sale_price':
							$product->set_sale_price( $new_value );
							break;
						case 'status':
							if ( ! $product->is_type( 'variation' ) && in_array( $new_value, array( 'publish', 'draft' ), true ) ) {
								$product->set_status( $new_value );
							}
							break;
						case 'stock_quantity':
							$product->set_manage_stock( true );
							$product->set_stock_quantity( (float) $new_value );
							if ( (float) $new_value > 0 ) {
								$product->set_stock_status( 'instock' );
							} elseif ( $product->backorders_allowed() ) {
								$product->set_stock_status( 'onbackorder' );
							} else {
								$product->set_stock_status( 'outofstock' );
							}
							break;
					}
				}

				$product->save();
				wc_delete_product_transients( $id );
				if ( $product->get_parent_id() ) {
					$parents_to_sync[ $product->get_parent_id() ] = true;
				}
				++$result['updated'];
			} catch ( Throwable $e ) {
				++$result['skipped'];
				$result['errors'][] = array( 'id' => $id, 'message' => $e->getMessage() );
			}
		}

		foreach ( array_keys( $parents_to_sync ) as $parent_id ) {
			try {
				if ( method_exists( 'WC_Product_Variable', 'sync' ) ) {
					WC_Product_Variable::sync( $parent_id );
				}
				if ( method_exists( 'WC_Product_Variable', 'sync_stock_status' ) ) {
					WC_Product_Variable::sync_stock_status( $parent_id );
				}
				wc_delete_product_transients( $parent_id );
			} catch ( Throwable $e ) {
				$result['errors'][] = array( 'id' => $parent_id, 'message' => 'همگام‌سازی محصول متغیر والد: ' . $e->getMessage() );
			}
		}

		return $result;
	}

	private static function export_rows( $category_id = 0, $include_children = true, $include_variations = true ) {
		yield array( 'ID', 'شناسه محصول', 'عنوان', 'وضعیت محصول', 'قیمت عادی', 'قیمت حراج', 'موجودی', 'دسته‌بندی', 'نوع محصول', 'ID والد', 'مشخصات تنوع' );

		$page     = 1;
		$seen     = array();
		$statuses = self::export_product_statuses();
		$category_slugs = $category_id ? self::category_slugs( $category_id, $include_children ) : array();

		do {
			$args = array(
				'limit'    => 200,
				'page'     => $page,
				'paginate' => true,
				'status'   => $statuses,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'return'   => 'objects',
			);
			if ( $category_slugs ) {
				$args['category'] = $category_slugs;
			}
			$query = wc_get_products( $args );

			$products = is_object( $query ) && isset( $query->products ) ? $query->products : array();
			$max_page = is_object( $query ) && isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 1;

			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				if ( ! isset( $seen[ $product->get_id() ] ) ) {
					$seen[ $product->get_id() ] = true;
					yield self::export_product_row( $product );
				}

				if ( $include_variations && $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $child_id ) {
						if ( isset( $seen[ $child_id ] ) ) {
							continue;
						}
						$variation = wc_get_product( $child_id );
						if ( $variation ) {
							$seen[ $child_id ] = true;
							yield self::export_product_row( $variation );
						}
					}
				}
			}

			++$page;
		} while ( $page <= $max_page );
	}

	private static function export_product_row( $product ) {
		$is_variation = $product->is_type( 'variation' );
		$is_variable  = $product->is_type( 'variable' );
		$stock        = $product->get_manage_stock() ? $product->get_stock_quantity( 'edit' ) : '';
		$regular      = (string) $product->get_regular_price( 'edit' );
		$sale         = (string) $product->get_sale_price( 'edit' );
		$status       = $is_variation ? 'تابع محصول والد' : self::status_display( $product->get_status( 'edit' ) );
		$parent_id    = (int) $product->get_parent_id();

		return array(
			(int) $product->get_id(),
			(string) $product->get_sku( 'edit' ),
			(string) $product->get_name( 'edit' ),
			$status,
			$regular,
			$sale,
			null === $stock ? '' : $stock,
			self::product_category_names( $product ),
			self::product_type_display( $product ),
			$parent_id ? $parent_id : '',
			$is_variation ? self::variation_attributes_display( $product ) : '',
		);
	}


	/**
	 * Return safe product post statuses for export without depending on
	 * optional/admin-only WooCommerce helper functions.
	 *
	 * Order statuses are deliberately excluded because WordPress post statuses
	 * are global and WooCommerce also registers wc-* order statuses there.
	 *
	 * @return string[]
	 */
	private static function export_product_statuses() {
		$statuses = array( 'publish', 'private', 'draft', 'pending', 'future' );

		if ( function_exists( 'get_post_stati' ) ) {
			$registered = get_post_stati( array(), 'names' );
			if ( is_array( $registered ) ) {
				$statuses = array_values( array_intersect( $statuses, $registered ) );
			}
		}

		if ( empty( $statuses ) ) {
			$statuses = array( 'publish' );
		}

		/**
		 * Filter product statuses included in the XLSX export.
		 *
		 * Custom product-status plugins may add their own status here.
		 * Do not add WooCommerce order statuses such as wc-processing.
		 *
		 * @param string[] $statuses Product post statuses.
		 */
		return array_values( array_unique( (array) apply_filters( 'wem_export_product_statuses', $statuses ) ) );
	}

	private static function snapshot( $product ) {
		$modified = $product->get_date_modified( 'edit' );
		return array(
			'id'              => (int) $product->get_id(),
			'name'            => (string) $product->get_name( 'edit' ),
			'sku'             => (string) $product->get_sku( 'edit' ),
			'regular_price'   => (string) $product->get_regular_price( 'edit' ),
			'sale_price'      => (string) $product->get_sale_price( 'edit' ),
			'status'          => (string) $product->get_status( 'edit' ),
			'stock_quantity'  => null === $product->get_stock_quantity( 'edit' ) ? null : (string) $product->get_stock_quantity( 'edit' ),
			'manage_stock'    => (bool) $product->get_manage_stock( 'edit' ),
			'stock_status'    => (string) $product->get_stock_status( 'edit' ),
			'type'            => (string) $product->get_type(),
			'parent_id'       => (int) $product->get_parent_id(),
			'modified_gmt_ts' => $modified ? (int) $modified->getTimestamp() : 0,
		);
	}

	private static function snapshot_matches( $current, $expected ) {
		$keys = array( 'id', 'name', 'sku', 'regular_price', 'sale_price', 'status', 'stock_quantity', 'manage_stock', 'stock_status', 'type', 'parent_id', 'modified_gmt_ts' );
		// A variation name can be derived dynamically from the parent title and attributes.
		// Ignore it during the concurrency check so a parent-title update in the same batch
		// does not incorrectly block valid variation price/stock updates.
		if ( isset( $expected['type'] ) && 'variation' === $expected['type'] ) {
			$keys = array_values( array_diff( $keys, array( 'name' ) ) );
		}
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $current ) || ! array_key_exists( $key, $expected ) ) {
				return false;
			}
			if ( in_array( $key, array( 'regular_price', 'sale_price', 'stock_quantity' ), true ) ) {
				$a = null === $current[ $key ] ? '' : (string) $current[ $key ];
				$b = null === $expected[ $key ] ? '' : (string) $expected[ $key ];
				if ( ! self::decimal_equal( $a, $b ) ) {
					return false;
				}
			} elseif ( $current[ $key ] !== $expected[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	private static function map_headers( $row ) {
		$aliases = array(
			'id'                  => array( 'id', 'product id', 'product_id', 'شناسه', 'آی دی', 'ای دی' ),
			'sku'                 => array( 'sku', 'شناسه محصول', 'کد محصول' ),
			'name'                => array( 'title', 'name', 'عنوان', 'نام محصول' ),
			'status'              => array( 'status', 'product status', 'وضعیت', 'وضعیت محصول' ),
			'regular_price'       => array( 'regular price', 'regular_price', 'price', 'قیمت', 'قیمت عادی', 'قیمت اصلی' ),
			'sale_price'          => array( 'sale price', 'sale_price', 'قیمت ویژه', 'قیمت فروش ویژه', 'قیمت حراج' ),
			'stock_quantity'      => array( 'stock', 'stock quantity', 'stock_quantity', 'موجودی', 'تعداد موجودی' ),
			'categories'          => array( 'category', 'categories', 'دسته بندی', 'دسته‌بندی' ),
			'product_type'        => array( 'type', 'product type', 'نوع محصول' ),
			'parent_id'           => array( 'parent id', 'parent_id', 'id والد', 'شناسه والد' ),
			'variation_attributes'=> array( 'variation', 'attributes', 'مشخصات تنوع', 'ویژگی های تنوع', 'ویژگی‌های تنوع' ),
		);
		$map = array();

		foreach ( $row as $index => $header ) {
			$normalized = self::normalize_header( $header );
			foreach ( $aliases as $field => $field_aliases ) {
				if ( isset( $map[ $field ] ) ) {
					continue;
				}
				foreach ( $field_aliases as $alias ) {
					if ( $normalized === self::normalize_header( $alias ) ) {
						$map[ $field ] = (int) $index;
						break;
					}
				}
			}
		}

		$required = array( 'id', 'sku', 'name', 'regular_price', 'sale_price', 'stock_quantity' );
		$missing  = array_diff( $required, array_keys( $map ) );
		if ( ! empty( $missing ) ) {
			$labels = array_map( array( __CLASS__, 'field_label' ), $missing );
			throw new RuntimeException( 'ستون‌های الزامی پیدا نشد: ' . implode( '، ', $labels ) );
		}
		return $map;
	}

	private static function normalize_header( $value ) {
		$value = self::strip_bom( (string) $value );
		$value = str_replace( array( 'ي', 'ى', 'ك', '_', '-' ), array( 'ی', 'ی', 'ک', ' ', ' ' ), $value );
		$value = self::lower( trim( preg_replace( '/\s+/u', ' ', $value ) ) );
		return $value;
	}

	private static function parse_decimal( $value, $allow_blank, $label ) {
		$value = trim( self::strip_bom( (string) $value ) );
		if ( '' === $value ) {
			return $allow_blank ? array( 'ok' => true, 'value' => '', 'error' => '' ) : array( 'ok' => false, 'value' => '', 'error' => $label . ' خالی است.' );
		}

		$value = self::normalize_digits( $value );
		$value = str_replace( array( "\xC2\xA0", ' ', '٬', ',' ), '', $value );
		$value = str_replace( '٫', '.', $value );

		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return array( 'ok' => false, 'value' => '', 'error' => $label . ' باید فقط عدد معتبر باشد.' );
		}

		$normalized = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, false, true ) : $value;
		if ( '' === $normalized ) {
			$normalized = '0';
		}
		return array( 'ok' => true, 'value' => self::canonical_decimal( $normalized ), 'error' => '' );
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
		if ( '' === $value || 0 === strpos( $value, '.' ) ) {
			$value = '0' . $value;
		}
		return $value;
	}

	private static function decimal_equal( $a, $b ) {
		return self::canonical_decimal( $a ) === self::canonical_decimal( $b );
	}

	/**
	 * Normalize harmless text differences created by Excel/Unicode round trips.
	 * This is used only for comparison; the original server value is preserved
	 * when two values are equivalent.
	 */
	private static function normalize_text_for_compare( $value ) {
		$value = self::strip_bom( (string) $value );

		if ( class_exists( 'Normalizer' ) ) {
			$normalized = Normalizer::normalize( $value, Normalizer::FORM_C );
			if ( false !== $normalized ) {
				$value = $normalized;
			}
		}

		// Normalize common Arabic forms to their Persian counterparts.
		$value = str_replace( array( 'ي', 'ى', 'ك' ), array( 'ی', 'ی', 'ک' ), $value );

		// Remove invisible characters that should not trigger a product update.
		$value = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value );

		// Convert Unicode separators/non-breaking spaces to one normal space.
		$value = str_replace( "\xC2\xA0", ' ', $value );
		$value = preg_replace( '/[\p{Z}\s]+/u', ' ', $value );

		return trim( (string) $value );
	}

	private static function text_equal( $a, $b ) {
		return self::normalize_text_for_compare( $a ) === self::normalize_text_for_compare( $b );
	}

	/**
	 * Compare SKUs safely. Older exports could let Excel turn a numeric-looking
	 * SKU into a number and remove its leading zeroes. In that one direction,
	 * preserve the current SKU instead of reporting a false change.
	 */
	private static function sku_equal( $new_value, $current_value ) {
		$new     = self::normalize_digits( self::normalize_text_for_compare( $new_value ) );
		$current = self::normalize_digits( self::normalize_text_for_compare( $current_value ) );

		if ( $new === $current ) {
			return true;
		}

		if ( preg_match( '/^0+\d+$/', $current ) && preg_match( '/^\d+$/', $new ) ) {
			$current_without_zeroes = ltrim( $current, '0' );
			$new_without_zeroes     = ltrim( $new, '0' );

			if ( '' === $current_without_zeroes ) {
				$current_without_zeroes = '0';
			}
			if ( '' === $new_without_zeroes ) {
				$new_without_zeroes = '0';
			}

			return $current_without_zeroes === $new_without_zeroes;
		}

		return false;
	}

	private static function normalize_digits( $value ) {
		return strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	private static function strip_bom( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
		$value = preg_replace( '/^\x{FEFF}/u', '', $value );
		return $value;
	}

	private static function cell( $row, $index ) {
		return isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
	}

	private static function row_is_empty( $row ) {
		foreach ( (array) $row as $value ) {
			if ( '' !== trim( self::strip_bom( (string) $value ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function render_category_dropdown( $name, $selected = 0, $show_all = true ) {
		wp_dropdown_categories(
			array(
				'taxonomy'        => 'product_cat',
				'hide_empty'      => false,
				'hierarchical'    => true,
				'show_option_all' => $show_all ? 'همه دسته‌بندی‌ها' : '',
				'name'            => $name,
				'selected'        => $selected,
				'orderby'         => 'name',
				'value_field'     => 'term_id',
			)
		);
	}

	private static function category_slugs( $category_id, $include_children ) {
		$ids = array( absint( $category_id ) );
		if ( $include_children ) {
			$children = get_term_children( $category_id, 'product_cat' );
			if ( is_wp_error( $children ) ) {
				throw new RuntimeException( $children->get_error_message() );
			}
			$ids = array_merge( $ids, array_map( 'absint', $children ) );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'include'    => array_values( array_unique( $ids ) ),
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			throw new RuntimeException( $terms->get_error_message() );
		}
		return array_values( array_filter( wp_list_pluck( $terms, 'slug' ) ) );
	}

	private static function product_category_names( $product ) {
		$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$terms      = get_the_terms( $product_id, 'product_cat' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return implode( '، ', wp_list_pluck( $terms, 'name' ) );
	}

	private static function product_type_display( $product ) {
		$labels = array(
			'simple'    => 'ساده',
			'variable'  => 'متغیر (والد)',
			'variation' => 'تنوع محصول',
			'grouped'   => 'گروه‌بندی‌شده',
			'external'  => 'خارجی/همکار',
		);
		$type = (string) $product->get_type();
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	private static function variation_attributes_display( $product ) {
		$parent     = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : false;
		$attributes = array();
		foreach ( (array) $product->get_attributes() as $taxonomy => $value ) {
			$taxonomy = str_replace( 'attribute_', '', (string) $taxonomy );
			$label    = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy, $parent ) : $taxonomy;
			$display  = (string) $value;
			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$display = $term->name;
				}
			}
			$attributes[] = $label . ': ' . $display;
		}
		return implode( ' | ', $attributes );
	}

	private static function status_display( $status ) {
		if ( 'publish' === $status ) {
			return 'منتشر شده';
		}
		if ( 'draft' === $status ) {
			return 'پیش‌نویس';
		}
		return (string) $status;
	}

	private static function parse_product_status( $value ) {
		$value = self::lower( trim( self::normalize_text_for_compare( self::strip_bom( (string) $value ) ) ) );
		if ( in_array( $value, array( 'publish', 'published', 'منتشر', 'منتشر شده', 'منتشرشده' ), true ) ) {
			return 'publish';
		}
		if ( in_array( $value, array( 'draft', 'پیش نویس', 'پیش‌نویس', 'پیشنویس' ), true ) ) {
			return 'draft';
		}
		return null;
	}


	private static function field_label( $field ) {
		$labels = array(
			'id'             => 'ID',
			'sku'            => 'شناسه محصول',
			'name'           => 'عنوان',
			'regular_price'  => 'قیمت عادی',
			'stock_quantity' => 'موجودی',
			'sale_price'     => 'قیمت حراج',
			'status'         => 'وضعیت محصول',
			'categories'     => 'دسته‌بندی',
			'product_type'   => 'نوع محصول',
			'parent_id'      => 'ID والد',
		);
		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	private static function display_value( $value ) {
		if ( 'publish' === (string) $value ) {
			return 'منتشر شده';
		}
		if ( 'draft' === (string) $value ) {
			return 'پیش‌نویس';
		}
		return '' === (string) $value ? '— خالی —' : (string) $value;
	}

	private static function export_url() {
		$url = add_query_arg( 'action', 'wem_export_products', admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, 'wem_export_products' );
	}

	private static function preview_key( $token ) {
		return 'wem_preview_' . get_current_user_id() . '_' . md5( $token );
	}

	private static function result_key( $token ) {
		return 'wem_result_' . get_current_user_id() . '_' . md5( $token );
	}

	private static function notice_key() {
		return 'wem_notice_' . get_current_user_id();
	}

	private static function redirect_notice( $type, $message ) {
		set_transient( self::notice_key(), array( 'type' => $type, 'message' => $message ), 120 );
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'product', 'page' => self::PAGE_SLUG ), admin_url( 'edit.php' ) ) );
		exit;
	}

	private static function upload_error_message( $code ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => 'حجم فایل از محدودیت سرور بیشتر است.',
			UPLOAD_ERR_FORM_SIZE  => 'حجم فایل از محدودیت فرم بیشتر است.',
			UPLOAD_ERR_PARTIAL    => 'فایل ناقص بارگذاری شده است.',
			UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده است.',
			UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور در دسترس نیست.',
			UPLOAD_ERR_CANT_WRITE => 'سرور نتوانست فایل موقت را ذخیره کند.',
			UPLOAD_ERR_EXTENSION  => 'یک افزونه PHP بارگذاری فایل را متوقف کرده است.',
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : 'خطای ناشناخته هنگام بارگذاری فایل.';
	}

	private static function assert_access() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازه مدیریت محصولات ووکامرس را ندارید.', 'webtanan-woocommerce-excel-manager' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			wp_die( esc_html__( 'ووکامرس باید فعال باشد.', 'webtanan-woocommerce-excel-manager' ) );
		}
	}

	private static function assert_runtime_requirements() {
		$missing = WEM_XLSX::missing_requirements();
		if ( ! empty( $missing ) ) {
			wp_die( esc_html( 'افزونه‌های PHP موردنیاز فعال نیستند: ' . implode( '، ', $missing ) ) );
		}
	}
}
