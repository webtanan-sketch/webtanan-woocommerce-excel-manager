<?php
/**
 * Price rules admin page.
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
			<p>ساخت قوانین افزایش و کاهش قیمت بر اساس ویژگی، دسته‌بندی یا محصول انتخابی.</p>

			<div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:900px;">
				<h2>ایجاد قانون جدید</h2>
				<table class="form-table">
					<tr>
						<th>نوع شرط</th>
						<td>
							<select>
								<option>ویژگی محصول</option>
								<option>دسته‌بندی</option>
								<option>محصول انتخابی</option>
								<option>SKU</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>مقدار شرط</th>
						<td><input type="text" class="regular-text" placeholder="مثال: 60x120"></td>
					</tr>
					<tr>
						<th>نوع تغییر</th>
						<td>
							<select>
								<option>افزایش مبلغ ثابت</option>
								<option>کاهش مبلغ ثابت</option>
								<option>افزایش درصدی</option>
								<option>کاهش درصدی</option>
							</select>
							<input type="number" placeholder="مقدار">
						</td>
					</tr>
				</table>
				<button class="button button-primary">ذخیره قانون</button>
			</div>

			<h2>قوانین موجود</h2>
			<table class="widefat striped">
				<thead><tr><th>شرط</th><th>مقدار</th><th>عملیات</th><th>وضعیت</th></tr></thead>
				<tbody>
					<tr><td>ویژگی محصول</td><td>---</td><td>---</td><td>فعال</td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
