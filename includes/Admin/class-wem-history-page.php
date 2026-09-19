<?php
/**
 * Audit history and rollback administration screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_History_Page {
	const PAGE_SLUG = 'wem-price-history';
	const PAGE_SIZE = 25;

	/**
	 * Register screen and handler hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 40 );
		add_action( 'admin_post_wem_rollback_operation', array( __CLASS__, 'handle_rollback' ) );
	}

	/**
	 * Register the history submenu.
	 *
	 * @return void
	 */
	public static function register() {
		add_submenu_page(
			WEM_Admin_Menu::MENU_SLUG,
			'تاریخچه تغییرات قیمت',
			'تاریخچه و بازگردانی',
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render grouped operations and recent field changes.
	 *
	 * @return void
	 */
	public static function render() {
		self::assert_access();

		$page       = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset     = ( $page - 1 ) * self::PAGE_SIZE;
		$operations = WEM_Price_Log::operations( self::PAGE_SIZE, $offset );
		$total      = WEM_Price_Log::operation_count();
		$recent     = WEM_Price_Log::recent( 20 );
		$notice     = self::pull_notice();
		?>
		<div class="wrap wem-shell" dir="rtl">
			<header class="wem-page-header wem-page-header--compact">
				<div><p class="wem-eyebrow">Audit &amp; Recovery</p><h1>تاریخچه تغییرات قیمت</h1><p>هر عملیات به‌صورت گروهی ثبت می‌شود. بازگردانی فقط زمانی انجام می‌شود که قیمت پس از عملیات دوباره تغییر نکرده باشد.</p></div>
			</header>

			<?php if ( $notice ) : ?>
				<div class="wem-alert wem-alert--<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo esc_attr( 'error' === $notice['type'] ? 'alert' : 'status' ); ?>" tabindex="-1"><?php echo esc_html( $notice['message'] ); ?></div>
			<?php endif; ?>

			<section class="wem-panel">
				<div class="wem-section-heading"><div><h2>عملیات‌ها</h2><p><?php echo esc_html( number_format_i18n( $total ) ); ?> عملیات ثبت‌شده</p></div></div>
				<div class="wem-table-wrap">
					<table class="widefat striped wem-data-table">
						<thead><tr><th>زمان</th><th>عنوان عملیات</th><th>نوع</th><th>محصول</th><th>تغییر</th><th>کاربر</th><th>وضعیت</th><th>اقدام</th></tr></thead>
						<tbody>
						<?php if ( empty( $operations ) ) : ?>
							<tr><td colspan="8"><div class="wem-empty-state"><span class="dashicons dashicons-backup" aria-hidden="true"></span><p>هنوز تغییر قیمتی ثبت نشده است.</p></div></td></tr>
						<?php else : ?>
							<?php foreach ( $operations as $operation ) : ?>
								<?php
								$user       = get_userdata( absint( $operation['user_id'] ) );
								$rolled      = (int) $operation['rolled_back_count'] >= (int) $operation['change_count'];
								$partial     = ! $rolled && (int) $operation['rolled_back_count'] > 0;
								$is_rollback = 'rollback' === $operation['change_type'];
								?>
								<tr>
									<td><?php echo esc_html( mysql2date( 'Y/m/d H:i', $operation['created_at'] ) ); ?></td>
									<td><strong><?php echo esc_html( $operation['operation_label'] ? $operation['operation_label'] : $operation['operation_id'] ); ?></strong><br><code><?php echo esc_html( $operation['operation_id'] ); ?></code></td>
									<td><?php echo esc_html( self::change_type_label( $operation['change_type'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $operation['product_count'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $operation['change_count'] ) ); ?></td>
									<td><?php echo esc_html( $user ? $user->display_name : 'کاربر حذف‌شده' ); ?></td>
									<td><span class="wem-status <?php echo esc_attr( $rolled ? 'wem-status--muted' : 'wem-status--success' ); ?>"><?php echo esc_html( $rolled ? 'بازگردانی‌شده' : ( $partial ? 'بازگردانی جزئی' : ( $is_rollback ? 'عملیات بازگردانی' : 'قابل بازگردانی' ) ) ); ?></span></td>
									<td>
									<?php if ( ! $rolled && ! $is_rollback ) : ?>
										<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-wem-confirm="این عملیات فقط برای محصولاتی بازگردانی می‌شود که قیمت‌شان بعداً تغییر نکرده است. ادامه می‌دهید؟">
											<input type="hidden" name="action" value="wem_rollback_operation">
											<input type="hidden" name="operation_id" value="<?php echo esc_attr( $operation['operation_id'] ); ?>">
											<?php wp_nonce_field( 'wem_rollback_' . $operation['operation_id'] ); ?>
											<button class="button" type="submit">بازگردانی</button>
										</form>
									<?php else : ?>—<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php self::pagination( $page, $total ); ?>
			</section>

			<section class="wem-panel">
				<div class="wem-section-heading"><div><h2>آخرین تغییرات جزئی</h2><p>ردیف‌های ثبت‌شده برای قیمت عادی و حراج</p></div></div>
				<div class="wem-table-wrap">
					<table class="widefat striped wem-data-table">
						<thead><tr><th>زمان</th><th>محصول</th><th>فیلد</th><th>قبل</th><th>بعد</th><th>علت</th></tr></thead>
						<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<?php $object_id = ! empty( $row['variation_id'] ) ? absint( $row['variation_id'] ) : absint( $row['product_id'] ); ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'Y/m/d H:i', $row['created_at'] ) ); ?></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $object_id ) ); ?>">#<?php echo esc_html( $object_id ); ?> — <?php echo esc_html( get_the_title( $object_id ) ); ?></a></td>
								<td><?php echo esc_html( 'sale_price' === $row['price_field'] ? 'قیمت حراج' : 'قیمت عادی' ); ?></td>
								<td><?php echo esc_html( self::format_price( $row['old_price'] ) ); ?></td>
								<td><strong><?php echo esc_html( self::format_price( $row['new_price'] ) ); ?></strong></td>
								<td><?php echo esc_html( $row['operation_label'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Execute a protected rollback.
	 *
	 * @return void
	 */
	public static function handle_rollback() {
		self::assert_access();
		$operation_id = isset( $_POST['operation_id'] ) ? sanitize_key( wp_unslash( $_POST['operation_id'] ) ) : '';
		check_admin_referer( 'wem_rollback_' . $operation_id );

		if ( ! $operation_id ) {
			self::redirect_notice( 'error', 'شناسه عملیات معتبر نیست.' );
		}

		$lock_key = 'wem_rollback_lock_' . md5( $operation_id );
		$lock     = (int) get_option( $lock_key, 0 );
		if ( $lock && ( time() - $lock ) > 15 * MINUTE_IN_SECONDS ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			self::redirect_notice( 'error', 'این عملیات هم‌اکنون در حال بازگردانی است.' );
		}

		$result = WEM_Price_Log::rollback_operation( $operation_id );
		delete_option( $lock_key );
		if ( is_wp_error( $result ) ) {
			self::redirect_notice( 'error', $result->get_error_message() );
		}

		$message = sprintf( '%d محصول/تنوع بازگردانی شد و %d مورد برای جلوگیری از بازنویسی رد شد.', $result['updated'], $result['skipped'] );
		if ( $result['errors'] ) {
			$message .= ' ' . implode( ' | ', array_slice( $result['errors'], 0, 8 ) );
		}
		self::redirect_notice( $result['errors'] ? 'warning' : 'success', $message );
	}

	/**
	 * Render operation pagination.
	 *
	 * @param int $page  Current page.
	 * @param int $total Total operations.
	 * @return void
	 */
	private static function pagination( $page, $total ) {
		$pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		if ( $pages <= 1 ) {
			return;
		}
		$base = add_query_arg( array( 'page' => self::PAGE_SLUG, 'paged' => '%#%' ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $pages, 'prev_text' => '«', 'next_text' => '»' ) ) ) . '</div></div>';
	}

	/**
	 * Map internal change types to Persian labels.
	 *
	 * @param string $type Change type.
	 * @return string
	 */
	private static function change_type_label( $type ) {
		$labels = array(
			'rule'         => 'قانون قیمت',
			'excel_import' => 'ورود Excel',
			'bulk_percent' => 'تغییر درصدی',
			'inline_edit'  => 'ویرایش جدولی',
			'rollback'     => 'بازگردانی',
			'manual'       => 'دستی',
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Format a stored price.
	 *
	 * @param string $price Price.
	 * @return string
	 */
	private static function format_price( $price ) {
		return '' === (string) $price ? '—' : number_format_i18n( (float) $price, floor( (float) $price ) === (float) $price ? 0 : 2 );
	}

	/**
	 * Enforce the WooCommerce management capability.
	 *
	 * @return void
	 */
	private static function assert_access() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'شما اجازه مدیریت تاریخچه قیمت را ندارید.', '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect with a user-scoped status message.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 * @return void
	 */
	private static function redirect_notice( $type, $message ) {
		set_transient( 'wem_history_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 180 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Pull and clear the current user's notice.
	 *
	 * @return array|null
	 */
	private static function pull_notice() {
		$key    = 'wem_history_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
		}
		return is_array( $notice ) ? $notice : null;
	}
}
