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
		?>
		<div class="wrap" dir="rtl">
			<h1>مدیریت ووکامرس وب‌تنان</h1>
			<p>مرکز مدیریت حرفه‌ای محصولات، قیمت‌گذاری و گزارش تغییرات.</p>
		</div>
		<?php
	}
}
