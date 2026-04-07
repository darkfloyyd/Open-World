<?php
/**
 * Open World Translate — Frontend Inline Translation Editor
 *
 * Adds a "Translate" button to the WordPress admin bar.
 * When activated:
 *  - Collects all gettext strings rendered on the page (msgid ↔ translation mapping)
 *  - Outputs the mapping as JSON for JS to scan DOM text nodes
 *  - JS highlights matching text and provides a sidebar for editing
 *
 * Only loads for users with 'manage_options' capability.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Inline {

	private static bool $active = false;

	/**
	 * Collected strings from gettext filter: msgid → [ domain, translation ]
	 */
	private array $collected = [];

	/**
	 * Initialize the inline editor.
	 * Called from open-world-translate.php on template_redirect.
	 */
	public function init(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if translate mode is toggled on via query param or cookie
		if ( isset( $_GET['ow_translate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $_GET['ow_translate'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				setcookie( 'ow_translate_mode', '1', 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				self::$active = true;
			} else {
				setcookie( 'ow_translate_mode', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				self::$active = false;
			}
		} elseif ( ! empty( $_COOKIE['ow_translate_mode'] ) ) {
			self::$active = true;
		}

		// Always add admin bar button
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_button' ], 999 );

		if ( self::$active ) {
			// Collect strings from gettext (no wrapping — just collect the mapping)
			add_filter( 'gettext', [ $this, 'collect_string' ], 99999, 3 );
			// Enqueue assets
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			// Output sidebar HTML + string map in footer
			add_action( 'wp_footer', [ $this, 'render_sidebar' ], 15 );
		}
	}

	/**
	 * Add "Translate" toggle to admin bar.
	 */
	public function add_admin_bar_button( \WP_Admin_Bar $admin_bar ): void {
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri         = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $uri;
		$base_url    = remove_query_arg( 'ow_translate', $current_url );

		if ( self::$active ) {
			$toggle_url = add_query_arg( 'ow_translate', '0', $base_url );
			$title      = '🌐 ' . __( 'Stop Translating', 'open-world-translate' );
			$class      = 'ow-ab-translate ow-ab-active';
		} else {
			$toggle_url = add_query_arg( 'ow_translate', '1', $base_url );
			$title      = '🌐 ' . __( 'Translate', 'open-world-translate' );
			$class      = 'ow-ab-translate';
		}

		$admin_bar->add_node( [
			'id'    => 'ow-translate-toggle',
			'title' => $title,
			'href'  => $toggle_url,
			'meta'  => [ 'class' => $class ],
		] );
	}

	/**
	 * Collect gettext string without modifying the output.
	 * Just records the mapping for JS to use later.
	 */
	public function collect_string( string $translation, string $text, string $domain ): string {
		// Only collect meaningful strings
		if ( strlen( $text ) >= 2 && $text === wp_strip_all_tags( $text ) ) {
			$key = md5( $text . '||' . $domain );
			if ( ! isset( $this->collected[ $key ] ) ) {
				$this->collected[ $key ] = [
					'msgid'       => $text,
					'domain'      => $domain,
					'translation' => $translation,
				];
			}
		}
		return $translation; // No wrapping — return unchanged!
	}

	/**
	 * Enqueue inline editor assets.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'ow-inline-editor',
			OW_PLUGIN_URL . 'assets/css/inline-editor.css',
			[],
			OW_VERSION
		);
		wp_enqueue_script(
			'ow-inline-editor',
			OW_PLUGIN_URL . 'assets/js/inline-editor.js',
			[],
			OW_VERSION,
			true
		);
		wp_localize_script( 'ow-inline-editor', 'owInline', [
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ow_inline_editor' ),
			'lang'     => OW_Router::get_current_lang(),
			'source'   => OW_Languages::get_source(),
			'provider' => get_option( 'ow_at_provider', 'google_free' ),
		] );
	}

	/**
	 * Render the sidebar HTML shell + string map in the footer.
	 */
	public function render_sidebar(): void {
		$languages = [];
		$all_langs = OW_Languages::get_all();
		$source    = OW_Languages::get_source();

		foreach ( $all_langs as $code => $lang_data ) {
			if ( $code === $source ) continue;
			$status = $lang_data['status'] ?? 'active';
			if ( $status === 'inactive' ) continue;
			$languages[] = [
				'code'   => $code,
				'name'   => $lang_data['name'] ?? $code,
				'flag'   => $lang_data['flag'] ?? '',
				'status' => $status,
			];
		}

		// Build the string map for JS: translation text → { msgid, domain }
		// JS will use this to find DOM text nodes and make them clickable
		$string_map = [];
		foreach ( $this->collected as $entry ) {
			$msgid = trim( $entry['msgid'] );
			$trans = trim( $entry['translation'] );
			if ( strlen( $msgid ) < 2 ) continue;
			
			$map_entry = [
				'msgid'  => $entry['msgid'],
				'domain' => $entry['domain'],
			];
			
			// If key already exists, prefer custom domains over 'default'
			$should_assign = function( $key ) use ( &$string_map, $map_entry ) {
				if ( ! isset( $string_map[ $key ] ) ) {
					return true;
				}
				if ( $string_map[ $key ]['domain'] === 'default' && $map_entry['domain'] !== 'default' ) {
					return true;
				}
				return false;
			};
			
			// Key by translation (what is visually rendered on page)
			if ( strlen( $trans ) >= 2 && $should_assign( $trans ) ) {
				$string_map[ $trans ] = $map_entry;
			}
			// Also key by msgid if different (source language fallback)
			if ( $msgid !== $trans && strlen( $msgid ) >= 2 && $should_assign( $msgid ) ) {
				$string_map[ $msgid ] = $map_entry;
			}
		}
		?>
		<div id="ow-inline-sidebar" class="ow-sidebar">
			<div class="ow-sidebar__header">
				<span class="ow-sidebar__title">🌐 <?php echo  esc_html__( 'Translate', 'open-world-translate' ) ?></span>
				<button id="ow-sidebar-close" class="ow-sidebar__close" title="<?php echo  esc_attr__( 'Close', 'open-world-translate' ) ?>">&times;</button>
			</div>
			<!-- Search / Filter bar -->
		<div class="ow-sidebar__search-wrap">
			<input
				type="search"
				id="ow-sidebar-search"
				class="ow-sidebar__search"
				placeholder="<?php echo esc_attr__( 'Search strings…', 'open-world-translate' ) ?>"
				autocomplete="off"
				spellcheck="false"
			>
			<span class="ow-sidebar__search-count" id="ow-search-count"></span>
		</div>

			<div class="ow-sidebar__body" id="ow-sidebar-body">
				<p class="ow-sidebar__loading">⏳ <?php echo  esc_html__( 'Loading strings…', 'open-world-translate' ) ?></p>
			</div>
			<div class="ow-sidebar__footer" id="ow-sidebar-footer" style="display:none">
				<button id="ow-at-all" class="ow-sidebar__btn ow-sidebar__btn--at"><?php echo  esc_html__( 'Auto-Translate All Empty', 'open-world-translate' ) ?></button>
				<button id="ow-save-all" class="ow-sidebar__btn ow-sidebar__btn--save"><?php echo  esc_html__( 'Save All', 'open-world-translate' ) ?></button>
			</div>
		</div>
		<?php
		wp_add_inline_script( 'ow-inline-editor',
			'window.owInlineLanguages = ' . wp_json_encode( $languages ) . ';' .
			' window.owStringMap = ' . wp_json_encode( $string_map, JSON_UNESCAPED_UNICODE ) . ';' .
			' console.log("[OW] Collected strings:", Object.keys(window.owStringMap).length);'
		);
	}

	// ── AJAX Handlers ────────────────────────────────────────────────────────

	/**
	 * Get all translations for a given msgid across all target languages.
	 */
	public function ajax_get_translations(): void {
		check_ajax_referer( 'ow_inline_editor' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$msgid  = sanitize_text_field( wp_unslash( $_POST['msgid'] ?? '' ) );
		$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? 'default' ) );

		if ( empty( $msgid ) ) wp_send_json_error( 'Missing msgid' );

		$source = OW_Languages::get_source();
		$all    = OW_Languages::get_all();
		$result = [];

		foreach ( $all as $code => $lang_data ) {
			if ( $code === $source ) continue;
			$status = $lang_data['status'] ?? 'active';
			if ( $status === 'inactive' ) continue;
			$translation = OW_DB::get_translation( $code, $msgid, $domain );
			$result[ $code ] = $translation ?? '';
		}

		wp_send_json_success( $result );
	}

	/**
	 * Save a single translation.
	 */
	public function ajax_save_translation(): void {
		check_ajax_referer( 'ow_inline_editor' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$msgid  = sanitize_text_field( wp_unslash( $_POST['msgid'] ?? '' ) );
		$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? 'default' ) );
		$lang   = sanitize_key( $_POST['lang'] ?? '' );
		$msgstr = sanitize_text_field( wp_unslash( $_POST['msgstr'] ?? '' ) );

		if ( empty( $msgid ) || empty( $lang ) ) {
			wp_send_json_error( 'Missing params' );
		}

		OW_DB::upsert( $lang, $domain, $msgid, $msgstr );
		wp_send_json_success( [ 'saved' => true ] );
	}

	/**
	 * Translate a single string via DeepL for a given target language.
	 */
	public function ajax_deepl_single(): void {
		check_ajax_referer( 'ow_inline_editor' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$msgid  = sanitize_text_field( wp_unslash( $_POST['msgid'] ?? '' ) );
		$lang   = sanitize_key( $_POST['lang'] ?? '' );

		if ( empty( $msgid ) || empty( $lang ) ) {
			wp_send_json_error( 'Missing params' );
		}

		$options = get_option( 'ow_deepl_settings', [] );
		$api_key = $options['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'DeepL API key not configured. Go to Settings.', 'open-world-translate' ) );
		}

		$source_lang = strtoupper( OW_Languages::get_source() );
		$target_lang = strtoupper( $lang );

		// DeepL expects EN-US / EN-GB for English targets
		if ( $target_lang === 'EN' ) $target_lang = 'EN-US';

		$results = OW_DeepL::translate( [ $msgid ], $source_lang, $target_lang );

		if ( ! $results['ok'] ) {
			wp_send_json_error( $results['error'] );
		}

		if ( empty( $results['translations'] ) ) {
			wp_send_json_error( __( 'No translation returned from DeepL.', 'open-world-translate' ) );
		}

		wp_send_json_success( [ 'translation' => $results['translations'][0] ] );
	}

	/**
	 * Translate a single string via Google Free for a given target language.
	 */
	public function ajax_google_free_single(): void {
		check_ajax_referer( 'ow_inline_editor' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$msgid  = sanitize_text_field( wp_unslash( $_POST['msgid'] ?? '' ) );
		$lang   = sanitize_key( $_POST['lang'] ?? '' );

		if ( empty( $msgid ) || empty( $lang ) ) {
			wp_send_json_error( 'Missing params' );
		}

		$source_lang = OW_Languages::get_source();

		$results = OW_Google_Free::translate( [ $msgid ], $source_lang, $lang );

		if ( ! $results['ok'] ) {
			wp_send_json_error( $results['error'] );
		}

		if ( empty( $results['translations'] ) ) {
			wp_send_json_error( __( 'No translation returned from Google Translate.', 'open-world-translate' ) );
		}

		wp_send_json_success( [ 'translation' => $results['translations'][0] ] );
	}
}
