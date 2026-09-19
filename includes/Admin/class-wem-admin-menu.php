<?php
/**
 * Unified Webtanan administration menu and dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Admin_Menu {
	const MENU_SLUG = 'webtanan-manager';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the product-management menu tree.
	 *
	 * @return void
	 */
	public static function register() {
		add_menu_page(
			'مدیریت ووکامرس وب‌تنان',
			'مدیریت ووکامرس',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-chart-area',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			'داشبورد مدیریت ووکامرس',
			'داشبورد',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the shared visual system only on plugin pages.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::is_plugin_page( $page ) ) {
			return;
		}

		$css_path = WEM_DIR . 'assets/css/admin.css';
		$js_path  = WEM_DIR . 'assets/js/admin.js';
		wp_enqueue_style( 'wem-admin', plugins_url( 'assets/css/admin.css', WEM_FILE ), array(), is_file( $css_path ) ? (string) filemtime( $css_path ) : WEM_VERSION );
		wp_enqueue_script( 'wem-admin', plugins_url( 'assets/js/admin.js', WEM_FILE ), array(), is_file( $js_path ) ? (string) filemtime( $js_path ) : WEM_VERSION, true );
	}

	/**
	 * Render dashboard metrics and primary workflows.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'شما اجازه مشاهده این صفحه را ندارید.', '', array( 'response' => 403 ) );
		}

		$counts        = wp_count_posts( 'product' );
		$product_count = 0;
		foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $status ) {
			$product_count += isset( $counts->{$status} ) ? (int) $counts->{$status} : 0;
		}
		$active_rules = WEM_Price_Rule_Repository::count_active();
		$latest       = WEM_Price_Log::latest();
		$last_import  = get_option( 'wem_last_import_summary', array() );
		$warnings     = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$warnings[] = 'ووکامرس فعال نیست.';
		}
		$missing = WEM_XLSX::missing_requirements();
		if ( $missing ) {
			$warnings[] = 'پیش‌نیازهای PHP برای Excel ناقص است: ' . implode( '، ', $missing );
		}
		?>
		<div class="wrap wem-shell" dir="rtl">
			<header class="wem-page-header">
				<div>
					<p class="wem-eyebrow">Webtanan Commerce Operations</p>
					<h1>مرکز مدیریت ووکامرس</h1>
					<p>قیمت، موجودی، Excel و تاریخچهٔ تغییرات را از یک فضای امن و قابل بازگشت مدیریت کنید.</p>
				</div>
				<a class="button button-primary wem-primary-action" href="<?php echo esc_url( admin_url( 'admin.php?page=wem-price-rules' ) ); ?>">ساخت قانون قیمت</a>
			</header>

			<?php if ( $warnings ) : ?>
				<div class="wem-alert wem-alert--warning" role="alert"><strong>نیازمند توجه:</strong> <?php echo esc_html( implode( ' ', $warnings ) ); ?></div>
			<?php endif; ?>

			<section class="wem-stat-grid" aria-label="خلاصه فروشگاه">
				<?php self::stat_card( 'dashicons-products', 'محصولات', number_format_i18n( $product_count ), 'همه وضعیت‌های قابل مدیریت' ); ?>
				<?php self::stat_card( 'dashicons-filter', 'قوانین فعال', number_format_i18n( $active_rules ), $active_rules ? 'آماده اجرا با پیش‌نمایش' : 'هنوز قانونی فعال نیست' ); ?>
				<?php self::stat_card( 'dashicons-backup', 'آخرین تغییر قیمت', $latest ? mysql2date( 'Y/m/d H:i', $latest['created_at'] ) : '—', $latest ? $latest['operation_label'] : 'تاریخچه‌ای ثبت نشده است' ); ?>
				<?php self::stat_card( 'dashicons-media-spreadsheet', 'آخرین ورود Excel', ! empty( $last_import['date'] ) ? mysql2date( 'Y/m/d H:i', $last_import['date'] ) : '—', ! empty( $last_import['updated'] ) ? number_format_i18n( $last_import['updated'] ) . ' ردیف به‌روزرسانی شد' : 'هنوز ورودی ثبت نشده است' ); ?>
			</section>

			<section class="wem-section">
				<div class="wem-section-heading"><div><p class="wem-eyebrow">گردش‌کارها</p><h2>از کجا شروع کنم؟</h2></div></div>
				<div class="wem-action-grid">
					<?php self::action_card( 'dashicons-media-spreadsheet', 'مدیریت Excel', 'خروجی بگیرید، فایل را آزمایش کنید و فقط پس از تأیید تغییرات را اعمال کنید.', admin_url( 'admin.php?page=' . WEM_Plugin::PAGE_SLUG ), 'ورود به Excel Manager' ); ?>
					<?php self::action_card( 'dashicons-filter', 'قوانین قیمت', 'شرط ویژگی، دسته، محصول یا SKU را به تغییر مبلغی یا درصدی متصل کنید.', admin_url( 'admin.php?page=wem-price-rules' ), 'مدیریت قوانین' ); ?>
					<?php self::action_card( 'dashicons-editor-table', 'ویرایش جدولی', 'قیمت و موجودی محصولات یک دسته را در جدول امن و دارای کنترل هم‌زمانی ویرایش کنید.', admin_url( 'admin.php?page=' . WEM_Price_Tools::EDITOR_PAGE_SLUG ), 'بازکردن جدول' ); ?>
					<?php self::action_card( 'dashicons-backup', 'تاریخچه و بازگردانی', 'علت هر تغییر را ببینید و عملیات واجد شرایط را بدون بازنویسی تغییرات جدیدتر بازگردانید.', admin_url( 'admin.php?page=' . WEM_History_Page::PAGE_SLUG ), 'مشاهده تاریخچه' ); ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Determine whether a page belongs to this plugin.
	 *
	 * @param string $page Page slug.
	 * @return bool
	 */
	private static function is_plugin_page( $page ) {
		$pages = array(
			self::MENU_SLUG,
			'webtanan-woocommerce-excel-manager',
			'webtanan-category-price-manager',
			'webtanan-inline-price-manager',
			'wem-price-rules',
			'wem-price-history',
		);
		return in_array( $page, $pages, true );
	}

	/**
	 * Render one dashboard metric.
	 *
	 * @param string $icon  Dashicon class.
	 * @param string $label Metric label.
	 * @param string $value Metric value.
	 * @param string $help  Supporting text.
	 * @return void
	 */
	private static function stat_card( $icon, $label, $value, $help ) {
		?>
		<article class="wem-stat-card">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<div><p><?php echo esc_html( $label ); ?></p><strong><?php echo esc_html( $value ); ?></strong><small><?php echo esc_html( $help ); ?></small></div>
		</article>
		<?php
	}

	/**
	 * Render one workflow card.
	 *
	 * @param string $icon        Dashicon class.
	 * @param string $title       Card title.
	 * @param string $description Description.
	 * @param string $url         Target URL.
	 * @param string $label       Link label.
	 * @return void
	 */
	private static function action_card( $icon, $title, $description, $url, $label ) {
		?>
		<article class="wem-action-card">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
			<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?><span aria-hidden="true"> ←</span></a>
		</article>
		<?php
	}
}
