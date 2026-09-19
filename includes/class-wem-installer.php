<?php
/**
 * Database installation and upgrades.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Installer {
	const DB_VERSION = '2.1.0';
	const OPTION_KEY = 'wem_database_version';

	/**
	 * Install plugin tables during activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Upgrade old installations lazily after a deploy.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::OPTION_KEY ) ) {
			self::install();
		}
	}

	/**
	 * Create or update every owned table.
	 *
	 * @return void
	 */
	private static function install() {
		WEM_Price_Rule_Repository::install();
		WEM_Price_Log::install();
		update_option( self::OPTION_KEY, self::DB_VERSION, false );
	}
}
