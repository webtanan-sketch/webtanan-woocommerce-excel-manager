<?php
/**
 * Price rules admin page foundation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Rules_Page {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 30 );
	}

	public static function register() {
		add_submenu_page(
			'webtanan-manager',
			'قوانین قیمت',
			'قوانین قیمت',
			'manage_woocommerce',
			'wem-price-rules',
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		?>
		<div class="wrap" dir="rtl">
			<h1>قوانین قیمت</h1>
			<p>ایجاد قوانین افزایش و کاهش قیمت بر اساس ویژگی، دسته‌بندی و محصولات انتخابی.</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>شرط</th>
						<th>مقدار</th>
						<th>عملیات</th>
						<th>وضعیت</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>ویژگی محصول</td>
						<td>---</td>
						<td>---</td>
						<td>آماده توسعه</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
