<?php
/**
 * Open World Translate — WooCommerce Integration
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_WooCommerce {

	public function register_hooks(): void {
		// Product title + descriptions (post meta approach)
		add_filter( 'the_title',                                 [ $this, 'translate_title' ],             10, 2 );
		add_filter( 'woocommerce_product_get_description',       [ $this, 'translate_description' ],       10, 2 );
		add_filter( 'woocommerce_product_get_short_description', [ $this, 'translate_short_description' ], 10, 2 );

		// Category names
		add_filter( 'get_term', [ $this, 'translate_term' ] );

		// Store order language at checkout
		add_action( 'woocommerce_checkout_order_created', [ $this, 'save_order_lang' ] );

		// Switch to order language for emails
		add_action( 'woocommerce_email_before_order_table', [ $this, 'switch_email_locale' ], 10, 1 );
		add_action( 'woocommerce_email_after_order_table',  [ $this, 'restore_email_locale' ] );

		// Translate checkout field labels
		add_filter( 'woocommerce_checkout_fields', [ $this, 'translate_checkout_fields' ] );

		// Admin meta box on product edit screen
		add_action( 'add_meta_boxes',    [ $this, 'add_product_meta_box' ] );
		add_action( 'save_post_product', [ $this, 'save_product_meta' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Fix WooCommerce Shop routing and notices
		add_action( 'pre_get_posts', [ $this, 'fix_shop_query' ], 5 );

		// Retain language prefix on WooCommerce redirects
		add_filter( 'woocommerce_add_to_cart_redirect',      [ $this, 'filter_wc_redirect' ], 999 );
		add_filter( 'woocommerce_login_redirect',            [ $this, 'filter_wc_redirect' ], 999 );
		add_filter( 'woocommerce_registration_redirect',     [ $this, 'filter_wc_redirect' ], 999 );
		add_filter( 'woocommerce_return_to_shop_redirect',   [ $this, 'filter_wc_redirect' ], 999 );
	}

	// ── Routing & Redirects ───────────────────────────────────────────────────

	public function fix_shop_query( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() ) return;

		$lang = OW_Router::get_current_lang();
		if ( ! $lang || $lang === OW_Languages::get_default() ) return;

		$shop_page_id = wc_get_page_id( 'shop' );
		if ( ! $shop_page_id ) return;

		$pagename = $q->get( 'pagename' );
		if ( ! $pagename ) return;

		$shop_page = get_post( $shop_page_id );
		if ( ! $shop_page ) return;

		// If the requested pagename matches the WooCommerce shop page slug,
		// force WP to treat this as the product archive (which enables notices and correct templates)
		if ( $pagename === $shop_page->post_name ) {
			$q->set( 'post_type', 'product' );
			$q->set( 'pagename', '' );
			$q->set( 'page_id', '' );
			$q->is_page              = false;
			$q->is_singular          = false;
			$q->is_post_type_archive = true;
			$q->is_archive           = true;
		}
	}

	public function filter_wc_redirect( $url ) {
		$router = new OW_Router();
		return $router->filter_url( $url );
	}

	// ── Product Fields ────────────────────────────────────────────────────────

	public function translate_title( string $title, int $id ): string {
		if ( is_admin() ) return $title;
		$lang = OW_Router::get_current_lang();
		if ( ! $lang || $lang === OW_Languages::get_default() ) return $title;
		return get_post_meta( $id, "ow_title_{$lang}", true ) ?: $title;
	}

	public function translate_description( string $desc, \WC_Product $product ): string {
		$lang = OW_Router::get_current_lang();
		if ( ! $lang || $lang === OW_Languages::get_default() ) return $desc;
		return get_post_meta( $product->get_id(), "ow_desc_{$lang}", true ) ?: $desc;
	}

	public function translate_short_description( string $desc, \WC_Product $product ): string {
		$lang = OW_Router::get_current_lang();
		if ( ! $lang || $lang === OW_Languages::get_default() ) return $desc;
		return get_post_meta( $product->get_id(), "ow_short_desc_{$lang}", true ) ?: $desc;
	}

	// ── Category Terms ────────────────────────────────────────────────────────

	public function translate_term( $term ) {
		if ( ! is_object( $term ) || ! isset( $term->taxonomy ) ) return $term;
		if ( ! in_array( $term->taxonomy, [ 'product_cat', 'product_tag' ], true ) ) return $term;

		$lang = OW_Router::get_current_lang();
		if ( ! $lang || $lang === OW_Languages::get_default() ) return $term;

		$translated_name = get_term_meta( $term->term_id, "ow_name_{$lang}", true );
		if ( $translated_name ) {
			$term->name = $translated_name;
		}
		return $term;
	}

	// ── Order Language ────────────────────────────────────────────────────────

	public function save_order_lang( \WC_Order $order ): void {
		$order->update_meta_data( '_ow_order_lang', OW_Router::get_current_lang() );
		$order->save();
	}

	public function switch_email_locale( \WC_Order $order ): void {
		$lang = $order->get_meta( '_ow_order_lang' );
		if ( $lang && $lang !== OW_Languages::get_default() ) {
			$locale = OW_Languages::get_locale( $lang );
			if ( $locale ) switch_to_locale( $locale );
		}
	}

	public function restore_email_locale(): void {
		restore_current_locale();
	}

	// ── Checkout Fields ───────────────────────────────────────────────────────

	public function translate_checkout_fields( array $fields ): array {
		$lang = OW_Router::get_current_lang();
		if ( ! $lang || $lang === OW_Languages::get_default() ) return $fields;

		foreach ( $fields as $group => &$group_fields ) {
			foreach ( $group_fields as &$field ) {
				if ( ! empty( $field['label'] ) ) {
					$field['label'] = OW_DB::get_translation( $lang, $field['label'], 'woocommerce' ) ?? $field['label'];
				}
				if ( ! empty( $field['placeholder'] ) ) {
					$field['placeholder'] = OW_DB::get_translation( $lang, $field['placeholder'], 'woocommerce' ) ?? $field['placeholder'];
				}
			}
		}
		return $fields;
	}

	// ── Product Meta Box ──────────────────────────────────────────────────────

	public function enqueue_admin_assets( $hook ): void {
		global $post;
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
		if ( ! $post || 'product' !== $post->post_type ) return;

		wp_register_script( 'ow-wc-admin', false, [ 'jquery' ], OW_VERSION, true );
		wp_enqueue_script( 'ow-wc-admin' );
		wp_add_inline_script( 'ow-wc-admin', '
		document.addEventListener("DOMContentLoaded", function() {
			document.querySelectorAll(".ow-product-tab-link").forEach(function(link) {
				link.addEventListener("click", function(e) {
					e.preventDefault();
					document.querySelectorAll(".ow-product-tab-panel").forEach(function(p) { p.style.display = "none"; });
					document.querySelectorAll(".ow-product-tab-link").forEach(function(l) { l.classList.remove("is-active"); });
					document.querySelector(this.getAttribute("href")).style.display = "";
					this.classList.add("is-active");
				});
			});
		});
		' );

		wp_register_style( 'ow-wc-admin', false, [], OW_VERSION );
		wp_enqueue_style( 'ow-wc-admin' );
		wp_add_inline_style( 'ow-wc-admin', '
		.ow-product-tab-nav { margin-bottom: 12px; }
		.ow-product-tab-link { display: inline-block; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px 4px 0 0; text-decoration: none; color: #444; background: #f7f7f7; margin-right: 3px; }
		.ow-product-tab-link.is-active { background: #fff; color: #2271b1; border-bottom-color: #fff; }
		' );
	}

	public function add_product_meta_box(): void {
		add_meta_box(
			'ow-product-translations',
			__( 'Open World Translates', 'open-world-translate' ),
			[ $this, 'render_product_meta_box' ],
			'product',
			'normal',
			'default'
		);
	}

	public function render_product_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'ow_product_meta', 'ow_product_nonce' );

		$targets = OW_Languages::get_target_languages();
		$names   = OW_Languages::get_names();
		$flags   = OW_Languages::get_flags();

		echo '<div class="ow-product-tabs">';
		echo '<nav class="ow-product-tab-nav">';
		foreach ( $targets as $i => $lang ) {
			printf(
				'<a href="#ow-tab-%s" class="ow-product-tab-link %s">%s %s</a>',
				esc_attr( $lang ),
				$i === 0 ? 'is-active' : '',
				esc_html( $flags[ $lang ] ?? '' ),
				esc_html( $names[ $lang ] ?? $lang )
			);
		}
		echo '</nav>';

		foreach ( $targets as $i => $lang ) {
			$title_val      = get_post_meta( $post->ID, "ow_title_{$lang}",      true );
			$short_desc_val = get_post_meta( $post->ID, "ow_short_desc_{$lang}", true );
			$desc_val       = get_post_meta( $post->ID, "ow_desc_{$lang}",       true );

			printf( '<div id="ow-tab-%s" class="ow-product-tab-panel" style="%s">', esc_attr( $lang ), $i !== 0 ? 'display:none' : '' );
			echo '<table class="form-table">';

			printf(
				'<tr><th><label>%s</label></th><td><input type="text" name="ow_title_%s" value="%s" style="width:100%%"></td></tr>',
				esc_html__( 'Product Title', 'open-world-translate' ),
				esc_attr( $lang ),
				esc_attr( $title_val )
			);
			printf(
				'<tr><th><label>%s</label></th><td><textarea name="ow_short_desc_%s" rows="3" style="width:100%%">%s</textarea></td></tr>',
				esc_html__( 'Short Description', 'open-world-translate' ),
				esc_attr( $lang ),
				esc_textarea( $short_desc_val )
			);
			printf(
				'<tr><th><label>%s</label></th><td><textarea name="ow_desc_%s" rows="6" style="width:100%%">%s</textarea></td></tr>',
				esc_html__( 'Full Description', 'open-world-translate' ),
				esc_attr( $lang ),
				esc_textarea( $desc_val )
			);

			echo '</table></div>';
		}

		echo '</div>';
	}

	public function save_product_meta( int $post_id ): void {
		$nonce = isset( $_POST['ow_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ow_product_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ow_product_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		foreach ( OW_Languages::get_target_languages() as $lang ) {
			if ( isset( $_POST[ "ow_title_{$lang}" ] ) ) {
				update_post_meta( $post_id, "ow_title_{$lang}",      sanitize_text_field( wp_unslash( $_POST[ "ow_title_{$lang}" ] ) ) );
			}
			if ( isset( $_POST[ "ow_short_desc_{$lang}" ] ) ) {
				update_post_meta( $post_id, "ow_short_desc_{$lang}", wp_kses_post( wp_unslash( $_POST[ "ow_short_desc_{$lang}" ] ) ) );
			}
			if ( isset( $_POST[ "ow_desc_{$lang}" ] ) ) {
				update_post_meta( $post_id, "ow_desc_{$lang}",       wp_kses_post( wp_unslash( $_POST[ "ow_desc_{$lang}" ] ) ) );
			}
		}
	}
}
