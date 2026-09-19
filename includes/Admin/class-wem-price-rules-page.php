<?php
/**
 * Saved pricing rules, preview, and execution screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WEM_Price_Rules_Page {
	const PAGE_SLUG   = 'wem-price-rules';
	const PREVIEW_TTL = 1800;

	/**
	 * Register page and action hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 30 );
		add_action( 'admin_post_wem_save_price_rule', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wem_delete_price_rule', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_wem_toggle_price_rule', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_wem_preview_price_rule', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_wem_apply_price_rule', array( __CLASS__, 'handle_apply' ) );
	}

	/**
	 * Register the rules submenu.
	 *
	 * @return void
	 */
	public static function register() {
		add_submenu_page(
			WEM_Admin_Menu::MENU_SLUG,
			'قوانین قیمت',
			'قوانین قیمت',
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render rule builder, saved rules, and an optional preview.
	 *
	 * @return void
	 */
	public static function render() {
		self::assert_access();

		$edit_id       = isset( $_GET['edit_rule'] ) ? absint( $_GET['edit_rule'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview_token = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing       = $edit_id ? WEM_Price_Rule_Repository::get( $edit_id ) : null;
		$rules         = WEM_Price_Rule_Repository::all();
		$notice        = self::pull_notice();
		$draft         = self::pull_draft();
		if ( $draft && ( ! $editing || absint( $draft['id'] ) === $edit_id ) ) {
			$editing = $draft;
		}
		?>
		<div class="wrap wem-shell" dir="rtl">
			<header class="wem-page-header wem-page-header--compact">
				<div><p class="wem-eyebrow">Pricing Automation</p><h1>قوانین قیمت</h1><p>شرط را تعریف کنید، نتیجه را روی داده‌های زنده پیش‌نمایش بگیرید و سپس با ثبت کامل تاریخچه اجرا کنید.</p></div>
			</header>

			<?php if ( $notice ) : ?>
				<div class="wem-alert wem-alert--<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo esc_attr( 'error' === $notice['type'] ? 'alert' : 'status' ); ?>" tabindex="-1"><?php echo esc_html( $notice['message'] ); ?></div>
			<?php endif; ?>

			<?php self::render_preview( $preview_token ); ?>

			<div class="wem-two-column">
				<?php self::render_form( $editing ); ?>
				<aside class="wem-panel wem-guide" aria-labelledby="wem-rule-guide-title">
					<p class="wem-eyebrow">راهنمای امن</p>
					<h2 id="wem-rule-guide-title">قانون چگونه اجرا می‌شود؟</h2>
					<ol class="wem-steps">
						<li><span>۱</span><div><strong>تطبیق</strong><p>محصولات و تنوع‌های منطبق با شرط پیدا می‌شوند.</p></div></li>
						<li><span>۲</span><div><strong>پیش‌نمایش</strong><p>قیمت قبل و بعد بدون ذخیره‌سازی نمایش داده می‌شود.</p></div></li>
						<li><span>۳</span><div><strong>کنترل هم‌زمانی</strong><p>اگر محصول بعد از پیش‌نمایش تغییر کند، همان مورد رد می‌شود.</p></div></li>
						<li><span>۴</span><div><strong>ثبت و بازگردانی</strong><p>هر فیلد قیمت با شناسه عملیات در تاریخچه ثبت می‌شود.</p></div></li>
					</ol>
				</aside>
			</div>

			<?php self::render_rules( $rules ); ?>
		</div>
		<?php
	}

	/**
	 * Save a new or edited rule.
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::assert_access();
		check_admin_referer( 'wem_save_price_rule' );

		$rule   = self::rule_from_request();
		$errors = WEM_Price_Rule_Engine::validate( $rule );
		if ( $errors ) {
			set_transient( self::draft_key(), $rule, 180 );
			self::redirect_notice( 'error', implode( ' ', $errors ), $rule['id'] );
		}

		if ( $rule['id'] && ! WEM_Price_Rule_Repository::get( $rule['id'] ) ) {
			self::redirect_notice( 'error', 'قانون موردنظر پیدا نشد.' );
		}

		$result = WEM_Price_Rule_Repository::save( $rule );
		if ( is_wp_error( $result ) ) {
			set_transient( self::draft_key(), $rule, 180 );
			self::redirect_notice( 'error', $result->get_error_message(), $rule['id'] );
		}

		delete_transient( self::draft_key() );
		self::redirect_notice( 'success', $rule['id'] ? 'قانون با موفقیت به‌روزرسانی شد.' : 'قانون با موفقیت ساخته شد.' );
	}

	/**
	 * Delete a saved rule.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		self::assert_access();
		$rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
		check_admin_referer( 'wem_delete_price_rule_' . $rule_id );
		if ( ! $rule_id || ! WEM_Price_Rule_Repository::delete( $rule_id ) ) {
			self::redirect_notice( 'error', 'حذف قانون انجام نشد.' );
		}
		self::redirect_notice( 'success', 'قانون حذف شد. تاریخچه عملیات‌های قبلی حفظ شده است.' );
	}

	/**
	 * Enable or disable a saved rule.
	 *
	 * @return void
	 */
	public static function handle_toggle() {
		self::assert_access();
		$rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
		check_admin_referer( 'wem_toggle_price_rule_' . $rule_id );
		if ( ! $rule_id || ! WEM_Price_Rule_Repository::toggle( $rule_id ) ) {
			self::redirect_notice( 'error', 'تغییر وضعیت قانون انجام نشد.' );
		}
		self::redirect_notice( 'success', 'وضعیت قانون تغییر کرد.' );
	}

	/**
	 * Build and persist a user-scoped rule preview.
	 *
	 * @return void
	 */
	public static function handle_preview() {
		self::assert_access();
		$rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
		check_admin_referer( 'wem_preview_price_rule_' . $rule_id );
		$rule = WEM_Price_Rule_Repository::get( $rule_id );
		if ( ! $rule ) {
			self::redirect_notice( 'error', 'قانون موردنظر پیدا نشد.' );
		}
		if ( empty( $rule['is_active'] ) ) {
			self::redirect_notice( 'error', 'برای پیش‌نمایش و اجرا ابتدا قانون را فعال کنید.' );
		}

		try {
			$products = WEM_Price_Rule_Engine::matching_products( $rule );
			$changes  = array();
			$reports  = array();
			$errors   = array();

			foreach ( $products as $product ) {
				$id = $product->get_id();
				if ( ! current_user_can( 'edit_post', $id ) ) {
					$errors[] = array( 'id' => $id, 'message' => 'اجازه ویرایش این محصول را ندارید.' );
					continue;
				}
				$product_changes = WEM_Price_Rule_Engine::changes_for_product( $product, $rule );
				if ( is_wp_error( $product_changes ) ) {
					$errors[] = array( 'id' => $id, 'message' => $product_changes->get_error_message() );
					continue;
				}
				if ( empty( $product_changes ) ) {
					continue;
				}
				$changes[] = array(
					'id'        => $id,
					'parent_id' => $product->get_parent_id(),
					'snapshot'  => WEM_Price_Rule_Engine::snapshot( $product ),
					'changes'   => $product_changes,
				);
				$reports[] = array(
					'id'      => $id,
					'sku'     => (string) $product->get_sku( 'edit' ),
					'name'    => self::display_name( $product ),
					'changes' => $product_changes,
				);
			}

			$token = strtolower( wp_generate_password( 24, false, false ) );
			set_transient(
				self::preview_key( $token ),
				array(
					'user_id'         => get_current_user_id(),
					'rule_id'         => $rule_id,
					'rule_fingerprint'=> self::fingerprint( $rule ),
					'created_at'      => time(),
					'changes'         => $changes,
					'reports'         => $reports,
					'errors'          => $errors,
				),
				self::PREVIEW_TTL
			);

			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'preview' => $token ), admin_url( 'admin.php' ) ) );
			exit;
		} catch ( Throwable $e ) {
			self::redirect_notice( 'error', 'ساخت پیش‌نمایش انجام نشد: ' . $e->getMessage() );
		}
	}

	/**
	 * Apply a previously generated preview and write audit rows.
	 *
	 * @return void
	 */
	public static function handle_apply() {
		self::assert_access();
		$token = isset( $_POST['preview_token'] ) ? sanitize_key( wp_unslash( $_POST['preview_token'] ) ) : '';
		check_admin_referer( 'wem_apply_price_rule_' . $token );

		$data = get_transient( self::preview_key( $token ) );
		if ( ! is_array( $data ) || empty( $data['changes'] ) || ! empty( $data['errors'] ) || (int) $data['user_id'] !== get_current_user_id() ) {
			self::redirect_notice( 'error', 'پیش‌نمایش معتبر نیست یا منقضی شده است.' );
		}
		$rule = WEM_Price_Rule_Repository::get( $data['rule_id'] );
		if ( ! $rule || empty( $rule['is_active'] ) || ! hash_equals( $data['rule_fingerprint'], self::fingerprint( $rule ) ) ) {
			self::redirect_notice( 'error', 'قانون پس از پیش‌نمایش تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.' );
		}

		$lock_key = 'wem_rule_apply_lock_' . md5( get_current_user_id() . '|' . $token );
		$lock     = (int) get_option( $lock_key, 0 );
		if ( $lock && ( time() - $lock ) > 15 * MINUTE_IN_SECONDS ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			self::redirect_notice( 'error', 'این پیش‌نمایش هم‌اکنون در حال اجراست یا قبلاً اجرا شده است.' );
		}

		$operation_id = WEM_Price_Log::new_operation_id( 'rule' );
		$updated      = 0;
		$skipped      = 0;
		$errors       = array();
		$parents      = array();

		foreach ( $data['changes'] as $item ) {
			$id = absint( $item['id'] );
			try {
				$product = wc_get_product( $id );
				if ( ! $product || ! current_user_can( 'edit_post', $id ) ) {
					throw new RuntimeException( 'محصول پیدا نشد یا اجازه ویرایش ندارید.' );
				}
				if ( ! WEM_Price_Rule_Engine::snapshot_matches( WEM_Price_Rule_Engine::snapshot( $product ), $item['snapshot'] ) ) {
					throw new RuntimeException( 'قیمت محصول پس از پیش‌نمایش تغییر کرده است.' );
				}

				foreach ( $item['changes'] as $field => $change ) {
					if ( 'regular_price' === $field ) {
						$product->set_regular_price( $change['new'] );
					} elseif ( 'sale_price' === $field ) {
						$product->set_sale_price( $change['new'] );
					}
				}
				$product->save();
				wc_delete_product_transients( $id );

				$logged = WEM_Price_Log::record_changes(
					$product,
					$item['changes'],
					array(
						'operation_id'    => $operation_id,
						'operation_label' => 'قانون: ' . $rule['name'],
						'change_type'     => 'rule',
						'rule_id'         => $rule['id'],
						'context'         => array( 'rule_name' => $rule['name'] ),
					)
				);
				if ( is_wp_error( $logged ) ) {
					$errors[] = 'ID ' . $id . ': قیمت ذخیره شد اما ثبت تاریخچه خطا داشت: ' . $logged->get_error_message();
				}
				if ( $product->get_parent_id() ) {
					$parents[ $product->get_parent_id() ] = true;
				}
				++$updated;
			} catch ( Throwable $e ) {
				++$skipped;
				$errors[] = 'ID ' . $id . ': ' . $e->getMessage();
			}
		}

		self::sync_parents( array_keys( $parents ) );
		WEM_Price_Rule_Repository::touch_last_run( $rule['id'] );
		delete_transient( self::preview_key( $token ) );
		delete_option( $lock_key );

		$message = sprintf( 'قانون اجرا شد: %d محصول/تنوع به‌روزرسانی و %d مورد رد شد. شناسه عملیات: %s', $updated, $skipped, $operation_id );
		if ( $errors ) {
			$message .= ' ' . implode( ' | ', array_slice( $errors, 0, 8 ) );
		}
		self::redirect_notice( $errors ? 'warning' : 'success', $message );
	}

	/**
	 * Render the rule builder form.
	 *
	 * @param array|null $rule Existing rule when editing.
	 * @return void
	 */
	private static function render_form( $rule ) {
		$rule = is_array( $rule ) ? $rule : array(
			'id'                 => 0,
			'name'               => '',
			'condition_type'     => 'attribute',
			'condition_key'      => '',
			'condition_values'   => array(),
			'include_children'   => true,
			'action_type'        => 'increase_fixed',
			'amount'             => '',
			'price_target'       => 'regular',
			'round_to'           => 1,
			'is_active'          => true,
		);
		$values     = implode( ', ', $rule['condition_values'] );
		$attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		?>
		<section class="wem-panel" aria-labelledby="wem-rule-form-title">
			<div class="wem-section-heading"><div><p class="wem-eyebrow"><?php echo esc_html( $rule['id'] ? 'ویرایش' : 'قانون جدید' ); ?></p><h2 id="wem-rule-form-title"><?php echo esc_html( $rule['id'] ? $rule['name'] : 'ساخت قانون قیمت' ); ?></h2></div></div>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wem-rule-form">
				<input type="hidden" name="action" value="wem_save_price_rule">
				<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>">
				<?php wp_nonce_field( 'wem_save_price_rule' ); ?>

				<div class="wem-field wem-field--full"><label for="wem-rule-name">نام قانون <span aria-hidden="true">*</span></label><input id="wem-rule-name" name="rule_name" type="text" class="regular-text" maxlength="191" value="<?php echo esc_attr( $rule['name'] ); ?>" required aria-describedby="wem-rule-name-help"><p id="wem-rule-name-help" class="description">مثال: افزایش قیمت سایز ۶۰×۱۲۰</p></div>
				<div class="wem-form-grid">
					<div class="wem-field"><label for="wem-condition-type">نوع شرط</label><select id="wem-condition-type" name="condition_type" data-wem-condition-select><option value="attribute" <?php selected( $rule['condition_type'], 'attribute' ); ?>>ویژگی محصول</option><option value="category" <?php selected( $rule['condition_type'], 'category' ); ?>>دسته‌بندی</option><option value="product" <?php selected( $rule['condition_type'], 'product' ); ?>>شناسه محصول</option><option value="sku" <?php selected( $rule['condition_type'], 'sku' ); ?>>SKU</option></select></div>
					<div class="wem-field" data-wem-condition="attribute"><label for="wem-attribute">ویژگی ووکامرس</label><select id="wem-attribute" name="condition_key"><option value="">— انتخاب ویژگی —</option><?php foreach ( $attributes as $attribute ) : $taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name ); ?><option value="<?php echo esc_attr( $taxonomy ); ?>" <?php selected( $rule['condition_key'], $taxonomy ); ?>><?php echo esc_html( $attribute->attribute_label ); ?></option><?php endforeach; ?></select></div>
					<div class="wem-field" data-wem-condition="attribute"><label for="wem-attribute-values">مقدار ویژگی</label><input id="wem-attribute-values" name="attribute_values" type="text" value="<?php echo esc_attr( 'attribute' === $rule['condition_type'] ? $values : '' ); ?>" placeholder="60x120, 80x80"><p class="description">نام یا slug مقدارها، جداشده با ویرگول</p></div>
					<div class="wem-field" data-wem-condition="category"><label for="wem-rule-category">دسته‌بندی</label><?php wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'hierarchical' => true, 'show_option_none' => '— انتخاب دسته —', 'option_none_value' => 0, 'name' => 'category_id', 'id' => 'wem-rule-category', 'selected' => 'category' === $rule['condition_type'] && isset( $rule['condition_values'][0] ) ? absint( $rule['condition_values'][0] ) : 0, 'orderby' => 'name', 'value_field' => 'term_id' ) ); ?><label class="wem-check"><input type="checkbox" name="include_children" value="1" <?php checked( ! empty( $rule['include_children'] ) ); ?>> شامل زیردسته‌ها</label></div>
					<div class="wem-field" data-wem-condition="product"><label for="wem-product-ids">شناسه محصولات</label><textarea id="wem-product-ids" name="product_ids" rows="3" placeholder="123, 456"><?php echo esc_textarea( 'product' === $rule['condition_type'] ? $values : '' ); ?></textarea><p class="description">ID محصول یا تنوع، جداشده با ویرگول</p></div>
					<div class="wem-field" data-wem-condition="sku"><label for="wem-product-skus">SKU محصولات</label><textarea id="wem-product-skus" name="product_skus" rows="3" placeholder="TILE-60120, TILE-8080"><?php echo esc_textarea( 'sku' === $rule['condition_type'] ? $values : '' ); ?></textarea><p class="description">کدها را با ویرگول یا خط جدید جدا کنید.</p></div>
					<div class="wem-field"><label for="wem-action-type">نوع تغییر</label><select id="wem-action-type" name="action_type"><option value="increase_fixed" <?php selected( $rule['action_type'], 'increase_fixed' ); ?>>افزایش مبلغ ثابت</option><option value="decrease_fixed" <?php selected( $rule['action_type'], 'decrease_fixed' ); ?>>کاهش مبلغ ثابت</option><option value="increase_percent" <?php selected( $rule['action_type'], 'increase_percent' ); ?>>افزایش درصدی</option><option value="decrease_percent" <?php selected( $rule['action_type'], 'decrease_percent' ); ?>>کاهش درصدی</option></select></div>
					<div class="wem-field"><label for="wem-action-amount">مقدار تغییر <span aria-hidden="true">*</span></label><input id="wem-action-amount" name="amount" type="text" inputmode="decimal" value="<?php echo esc_attr( $rule['amount'] ); ?>" required></div>
					<div class="wem-field"><label for="wem-price-target">قیمت هدف</label><select id="wem-price-target" name="price_target"><option value="regular" <?php selected( $rule['price_target'], 'regular' ); ?>>قیمت عادی</option><option value="sale" <?php selected( $rule['price_target'], 'sale' ); ?>>قیمت حراج</option><option value="both" <?php selected( $rule['price_target'], 'both' ); ?>>هر دو قیمت</option></select></div>
					<div class="wem-field"><label for="wem-round-to">گرد کردن</label><select id="wem-round-to" name="round_to"><?php foreach ( array( 1 => 'بدون گرد کردن خاص', 10 => 'نزدیک‌ترین ۱۰', 100 => 'نزدیک‌ترین ۱۰۰', 1000 => 'نزدیک‌ترین ۱٬۰۰۰', 10000 => 'نزدیک‌ترین ۱۰٬۰۰۰' ) as $round => $label ) : ?><option value="<?php echo esc_attr( $round ); ?>" <?php selected( $rule['round_to'], $round ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
				</div>
				<label class="wem-check wem-check--prominent"><input type="checkbox" name="is_active" value="1" <?php checked( ! empty( $rule['is_active'] ) ); ?>> قانون فعال باشد</label>
				<div class="wem-form-actions"><button class="button button-primary" type="submit"><?php echo esc_html( $rule['id'] ? 'ذخیره تغییرات' : 'ساخت قانون' ); ?></button><?php if ( $rule['id'] ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">انصراف</a><?php endif; ?></div>
			</form>
		</section>
		<?php
	}

	/**
	 * Render saved rules and their actions.
	 *
	 * @param array $rules Saved rules.
	 * @return void
	 */
	private static function render_rules( array $rules ) {
		?>
		<section class="wem-panel">
			<div class="wem-section-heading"><div><p class="wem-eyebrow">کتابخانه قوانین</p><h2>قوانین موجود</h2></div><span class="wem-count-badge"><?php echo esc_html( number_format_i18n( count( $rules ) ) ); ?> قانون</span></div>
			<div class="wem-table-wrap">
				<table class="widefat striped wem-data-table">
					<thead><tr><th>نام و شرط</th><th>تغییر قیمت</th><th>آخرین اجرا</th><th>وضعیت</th><th>اقدام‌ها</th></tr></thead>
					<tbody>
					<?php if ( empty( $rules ) ) : ?><tr><td colspan="5"><div class="wem-empty-state"><span class="dashicons dashicons-filter" aria-hidden="true"></span><p>هنوز قانونی نساخته‌اید. فرم بالا آماده است.</p></div></td></tr><?php endif; ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $rule['name'] ); ?></strong><br><span class="description"><?php echo esc_html( self::condition_label( $rule ) ); ?></span></td>
							<td><?php echo esc_html( self::action_label( $rule ) ); ?></td>
							<td><?php echo esc_html( $rule['last_run_at'] ? mysql2date( 'Y/m/d H:i', $rule['last_run_at'] ) : 'اجرا نشده' ); ?></td>
							<td><span class="wem-status <?php echo esc_attr( $rule['is_active'] ? 'wem-status--success' : 'wem-status--muted' ); ?>"><?php echo esc_html( $rule['is_active'] ? 'فعال' : 'غیرفعال' ); ?></span></td>
							<td><div class="wem-row-actions">
								<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'edit_rule' => $rule['id'] ), admin_url( 'admin.php' ) ) ); ?>">ویرایش</a>
								<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wem_preview_price_rule"><input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>"><?php wp_nonce_field( 'wem_preview_price_rule_' . $rule['id'] ); ?><button class="button button-primary" type="submit">پیش‌نمایش اجرا</button></form>
								<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="wem_toggle_price_rule"><input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>"><?php wp_nonce_field( 'wem_toggle_price_rule_' . $rule['id'] ); ?><button class="button" type="submit"><?php echo esc_html( $rule['is_active'] ? 'غیرفعال‌کردن' : 'فعال‌کردن' ); ?></button></form>
								<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-wem-confirm="این قانون حذف شود؟ تاریخچه اجراهای قبلی باقی می‌ماند."><input type="hidden" name="action" value="wem_delete_price_rule"><input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>"><?php wp_nonce_field( 'wem_delete_price_rule_' . $rule['id'] ); ?><button class="button button-link-delete" type="submit">حذف</button></form>
							</div></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a stored preview and its confirmation action.
	 *
	 * @param string $token Preview token.
	 * @return void
	 */
	private static function render_preview( $token ) {
		if ( ! $token ) {
			return;
		}
		$data = get_transient( self::preview_key( $token ) );
		if ( ! is_array( $data ) || (int) $data['user_id'] !== get_current_user_id() ) {
			echo '<div class="wem-alert wem-alert--error" role="alert">پیش‌نمایش منقضی یا نامعتبر است. دوباره پیش‌نمایش بگیرید.</div>';
			return;
		}
		$reports = isset( $data['reports'] ) ? $data['reports'] : array();
		$errors  = isset( $data['errors'] ) ? $data['errors'] : array();
		?>
		<section class="wem-panel wem-preview-panel" aria-labelledby="wem-preview-title">
			<div class="wem-section-heading"><div><p class="wem-eyebrow">Dry Run</p><h2 id="wem-preview-title">پیش‌نمایش اجرای قانون</h2></div><div class="wem-summary-badges"><span><strong><?php echo esc_html( number_format_i18n( count( $reports ) ) ); ?></strong> قابل تغییر</span><span><strong><?php echo esc_html( number_format_i18n( count( $errors ) ) ); ?></strong> خطا</span></div></div>
			<?php if ( $errors ) : ?><div class="wem-alert wem-alert--error" role="alert" tabindex="-1"><strong>اجرای نهایی غیرفعال است.</strong> خطاها را برطرف یا قانون را دقیق‌تر کنید.</div><?php elseif ( empty( $reports ) ) : ?><div class="wem-alert wem-alert--info" role="status">هیچ قیمت قابل تغییری برای این قانون پیدا نشد.</div><?php else : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wem-confirm-bar" data-wem-confirm="قیمت‌های نمایش‌داده‌شده اعمال و در تاریخچه ثبت شوند؟"><input type="hidden" name="action" value="wem_apply_price_rule"><input type="hidden" name="preview_token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'wem_apply_price_rule_' . $token ); ?><p>فقط همین تغییرات پس از کنترل دوبارهٔ قیمت فعلی اعمال می‌شوند.</p><button class="button button-primary" type="submit">تأیید و اجرای قانون</button></form>
			<?php endif; ?>
			<?php if ( $reports ) : ?><div class="wem-table-wrap"><table class="widefat striped wem-data-table"><thead><tr><th>ID</th><th>SKU</th><th>محصول / تنوع</th><th>تغییرات</th></tr></thead><tbody><?php foreach ( array_slice( $reports, 0, 1000 ) as $report ) : ?><tr><td><?php echo esc_html( $report['id'] ); ?></td><td><?php echo esc_html( $report['sku'] ); ?></td><td><?php echo esc_html( $report['name'] ); ?></td><td><?php foreach ( $report['changes'] as $field => $change ) : ?><div><span><?php echo esc_html( 'sale_price' === $field ? 'حراج' : 'عادی' ); ?>:</span> <?php echo esc_html( self::format_price( $change['old'] ) ); ?> ← <strong><?php echo esc_html( self::format_price( $change['new'] ) ); ?></strong></div><?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
			<?php if ( $errors ) : ?><div class="wem-table-wrap"><table class="widefat striped wem-data-table"><thead><tr><th>ID</th><th>خطا</th></tr></thead><tbody><?php foreach ( array_slice( $errors, 0, 200 ) as $error ) : ?><tr><td><?php echo esc_html( $error['id'] ); ?></td><td><?php echo esc_html( $error['message'] ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Normalize rule form input.
	 *
	 * @return array
	 */
	// Nonce verification is performed by handle_save() before this private parser is called.
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	private static function rule_from_request() {
		$type   = isset( $_POST['condition_type'] ) ? sanitize_key( wp_unslash( $_POST['condition_type'] ) ) : '';
		$values = array();
		$key    = '';
		if ( 'attribute' === $type ) {
			$key    = isset( $_POST['condition_key'] ) ? sanitize_key( wp_unslash( $_POST['condition_key'] ) ) : '';
			$values = self::parse_list( isset( $_POST['attribute_values'] ) ? wp_unslash( $_POST['attribute_values'] ) : '' );
		} elseif ( 'category' === $type ) {
			$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
			$values      = $category_id ? array( $category_id ) : array();
		} elseif ( 'product' === $type ) {
			$values = array_values( array_filter( array_map( 'absint', self::parse_list( isset( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : '' ) ) ) );
		} elseif ( 'sku' === $type ) {
			$values = self::parse_list( isset( $_POST['product_skus'] ) ? wp_unslash( $_POST['product_skus'] ) : '' );
		}

		return array(
			'id'                 => isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0,
			'name'               => isset( $_POST['rule_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_name'] ) ) : '',
			'condition_type'     => $type,
			'condition_key'      => $key,
			'condition_values'   => array_values( array_unique( $values ) ),
			'include_children'   => ! empty( $_POST['include_children'] ),
			'action_type'        => isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '',
			'amount'             => self::parse_decimal( isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : '' ),
			'price_target'       => isset( $_POST['price_target'] ) ? sanitize_key( wp_unslash( $_POST['price_target'] ) ) : '',
			'round_to'           => isset( $_POST['round_to'] ) ? absint( $_POST['round_to'] ) : 1,
			'is_active'          => ! empty( $_POST['is_active'] ),
		);
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Parse comma/newline-separated values.
	 *
	 * @param string $value Raw list.
	 * @return array
	 */
	private static function parse_list( $value ) {
		$items = preg_split( '/[,،\r\n]+/u', (string) $value );
		return array_values( array_filter( array_map( 'sanitize_text_field', $items ), 'strlen' ) );
	}

	/**
	 * Parse Persian/Arabic or Latin decimal input.
	 *
	 * @param string $value Raw number.
	 * @return string
	 */
	private static function parse_decimal( $value ) {
		$value = strtr( trim( (string) $value ), array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.' ) );
		return str_replace( array( ' ', "\xC2\xA0", '٬', ',' ), '', $value );
	}

	/**
	 * Create a stable fingerprint for preview invalidation.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function fingerprint( array $rule ) {
		return hash( 'sha256', wp_json_encode( $rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Sync variable parents after variation updates.
	 *
	 * @param array $parent_ids Parent IDs.
	 * @return void
	 */
	private static function sync_parents( array $parent_ids ) {
		foreach ( array_unique( array_map( 'absint', $parent_ids ) ) as $parent_id ) {
			if ( ! $parent_id ) {
				continue;
			}
			try {
				WC_Product_Variable::sync( $parent_id );
				WC_Product_Variable::sync_stock_status( $parent_id );
				wc_delete_product_transients( $parent_id );
			} catch ( Throwable $e ) {
				// Child prices are already saved; a future WooCommerce sync can repair caches.
			}
		}
	}

	/**
	 * Build a readable product/variation label.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function display_name( $product ) {
		if ( ! $product->is_type( 'variation' ) ) {
			return (string) $product->get_name( 'edit' );
		}
		$parent = wc_get_product( $product->get_parent_id() );
		$base   = $parent ? $parent->get_name( 'edit' ) : $product->get_name( 'edit' );
		$parts  = array();
		foreach ( $product->get_attributes() as $taxonomy => $value ) {
			$parts[] = wc_attribute_label( str_replace( 'attribute_', '', $taxonomy ), $parent ) . ': ' . $value;
		}
		return $base . ( $parts ? ' — ' . implode( ' | ', $parts ) : ' — تنوع #' . $product->get_id() );
	}

	/**
	 * Describe a saved condition.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function condition_label( array $rule ) {
		$values = implode( '، ', $rule['condition_values'] );
		if ( 'attribute' === $rule['condition_type'] ) {
			return 'ویژگی ' . wc_attribute_label( $rule['condition_key'] ) . ': ' . $values;
		}
		if ( 'category' === $rule['condition_type'] ) {
			$term = get_term( absint( $rule['condition_values'][0] ), 'product_cat' );
			return 'دسته: ' . ( $term && ! is_wp_error( $term ) ? $term->name : $values ) . ( $rule['include_children'] ? ' + زیردسته‌ها' : '' );
		}
		return ( 'product' === $rule['condition_type'] ? 'محصول: ' : 'SKU: ' ) . $values;
	}

	/**
	 * Describe a pricing action.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function action_label( array $rule ) {
		$labels = array( 'increase_fixed'=>'افزایش مبلغی','decrease_fixed'=>'کاهش مبلغی','increase_percent'=>'افزایش درصدی','decrease_percent'=>'کاهش درصدی' );
		$target = array( 'regular'=>'قیمت عادی','sale'=>'قیمت حراج','both'=>'هر دو قیمت' );
		$suffix = false !== strpos( $rule['action_type'], 'percent' ) ? '٪' : '';
		return $labels[ $rule['action_type'] ] . ' ' . number_format_i18n( (float) $rule['amount'], floor( (float) $rule['amount'] ) === (float) $rule['amount'] ? 0 : 2 ) . $suffix . ' روی ' . $target[ $rule['price_target'] ];
	}

	/**
	 * Format price for preview.
	 *
	 * @param string $price Price.
	 * @return string
	 */
	private static function format_price( $price ) {
		return '' === (string) $price ? '—' : number_format_i18n( (float) $price, floor( (float) $price ) === (float) $price ? 0 : 2 );
	}

	/**
	 * User-scoped preview transient key.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function preview_key( $token ) {
		return 'wem_rule_preview_' . get_current_user_id() . '_' . md5( $token );
	}

	/**
	 * User-scoped rule-form draft key.
	 *
	 * @return string
	 */
	private static function draft_key() {
		return 'wem_rule_draft_' . get_current_user_id();
	}

	/**
	 * Enforce permissions and WooCommerce availability.
	 *
	 * @return void
	 */
	private static function assert_access() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'شما اجازه مدیریت قوانین قیمت را ندارید.', '', array( 'response' => 403 ) );
		}
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_products' ) ) {
			wp_die( 'ووکامرس باید فعال باشد.' );
		}
	}

	/**
	 * Redirect with a user-scoped notice.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 * @param int    $edit_id Optional rule to reopen.
	 * @return void
	 */
	private static function redirect_notice( $type, $message, $edit_id = 0 ) {
		set_transient( 'wem_rules_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 180 );
		$args = array( 'page' => self::PAGE_SLUG );
		if ( $edit_id ) {
			$args['edit_rule'] = absint( $edit_id );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Pull and clear the current user's notice.
	 *
	 * @return array|null
	 */
	private static function pull_notice() {
		$key    = 'wem_rules_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
		}
		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Restore and clear form data after validation fails.
	 *
	 * @return array|null
	 */
	private static function pull_draft() {
		$key   = self::draft_key();
		$draft = get_transient( $key );
		if ( $draft ) {
			delete_transient( $key );
		}
		return is_array( $draft ) ? $draft : null;
	}
}
