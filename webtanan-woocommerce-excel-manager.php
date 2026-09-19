<?php
/**
 * Plugin Name: Webtanan WooCommerce Excel Manager
 * Description: مدیریت جامع اکسل، قیمت، موجودی، وضعیت و تغییرات دسته‌ای محصولات و تنوع‌های ووکامرس.
 * Version:     2.1.0
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

define( 'WEM_VERSION', '2.1.0' );
define( 'WEM_FILE', __FILE__ );
define( 'WEM_DIR', plugin_dir_path( __FILE__ ) );

require_once WEM_DIR . 'includes/class-wem-xlsx.php';
require_once WEM_DIR . 'includes/class-wem-plugin.php';
require_once WEM_DIR . 'includes/class-wem-price-tools.php';
require_once WEM_DIR . 'includes/Pricing/class-wem-price-rule-engine.php';
require_once WEM_DIR . 'includes/Pricing/class-wem-price-rule-repository.php';
require_once WEM_DIR . 'includes/Audit/class-wem-price-log.php';
require_once WEM_DIR . 'includes/class-wem-installer.php';
require_once WEM_DIR . 'includes/Admin/class-wem-admin-menu.php';
require_once WEM_DIR . 'includes/Admin/class-wem-price-rules-page.php';
require_once WEM_DIR . 'includes/Admin/class-wem-history-page.php';

register_activation_hook( WEM_FILE, array( 'WEM_Installer', 'activate' ) );

add_action(
	'plugins_loaded',
	function () {
		WEM_Installer::maybe_upgrade();
		WEM_Plugin::init();
		WEM_Price_Tools::init();

		if ( class_exists( 'WEM_Admin_Menu' ) ) {
			WEM_Admin_Menu::init();
		}

		if ( class_exists( 'WEM_Price_Rules_Page' ) ) {
			WEM_Price_Rules_Page::init();
		}

		if ( class_exists( 'WEM_History_Page' ) ) {
			WEM_History_Page::init();
		}
	}
);
