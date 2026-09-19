<?php
/**
 * Admin menu foundation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Admin_Menu {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register() {
		add_submenu_page(
			'edit.php?post_type=product',
			'Webtanan Manager',
			'مدیریت ووکامرس',
			'manage_woocommerce',
			'webtanan-manager',
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$cards = array(
			'قوانین قیمت' => 'ایجاد افزایش یا کاهش بر اساس ویژگی، دسته و محصول',
			'تاریخچه تغییرات' => 'ثبت کامل قیمت قبل و بعد از هر عملیات',
			'Excel Manager' => 'ورود و خروج امن اطلاعات محصولات',
		);
		?>
		<div class="wrap" dir="rtl">
			<h1>مدیریت ووکامرس وب‌تنان</h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
			<?php foreach ( $cards as $title => $desc ) : ?>
				<div style="background:#fff;border:1px solid #ddd;border-radius:12px;padding:20px">
					<h2><?php echo esc_html( $title ); ?></h2>
					<p><?php echo esc_html( $desc ); ?></p>
				</div>
			<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
