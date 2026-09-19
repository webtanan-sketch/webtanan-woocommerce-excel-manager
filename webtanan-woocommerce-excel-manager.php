<?php
/**
 * Plugin Name: Webtanan WooCommerce Excel Manager
 * Description: مدیریت جامع اکسل، قیمت، موجودی، وضعیت و تغییرات دسته‌ای محصولات و تنوع‌های ووکامرس.
 * Version:     2.0.0
 * Author:      Webtanan
 * Text Domain: webtanan-woocommerce-excel-manager
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WEM_VERSION', '2.0.0' );
define( 'WEM_FILE', __FILE__ );
define( 'WEM_DIR', plugin_dir_path( __FILE__ ) );

require_once WEM_DIR . 'includes/class-wem-xlsx.php';
require_once WEM_DIR . 'includes/class-wem-plugin.php';
require_once WEM_DIR . 'includes/class-wem-price-tools.php';

add_action(
	'plugins_loaded',
	function () {
		WEM_Plugin::init();
		WEM_Price_Tools::init();
	}
);
