<?php
/**
 * Open World — URL Router + Language Detection
 *
 * URL Schema: /{lang_code}/{slug} — default lang has no prefix (stays at /)
 * Detection: URL prefix > Cookie > Accept-Language header > default lang
 *
 * Routing strategy:
 *  - We add rewrite rules so /pl/ and /pl/some-page/ are recognised by WP.
 *  - The ow_lang query var is stored and used by OW_Engine to pick translations.
 *  - We hook `parse_request` to strip the lang prefix before WP matches pages,
 *    so WP sees the normal slug and serves the right content.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Router {

	private static ?string $current_lang = null;

	// ── Language Detection ────────────────────────────────────────────────────

	public static function get_current_lang(): string {
		if ( self::$current_lang !== null ) {
			return self::$current_lang;
		}

		$url      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$request  = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$segments = explode( '/', $request );
		$first    = strtolower( $segments[0] ?? '' );

		// URL prefix has absolute priority: /pl/ → pl, /es/ → es
		if ( $first && OW_Languages::is_valid( $first ) ) {
			self::$current_lang = $first;
			return self::$current_lang;
		}

		// No URL prefix → always the default language.
		// The cookie is only used by redirect_if_needed() for auto-redirect on first visit.
		self::$current_lang = OW_Languages::get_default();
		return self::$current_lang;
	}

	/**
	 * Parse RFC 5646 Accept-Language header. Returns best match or fallback lang.
	 */
	public static function detect_from_header( string $header ): string {
		preg_match_all( '/([a-z]{2,3})(?:-[A-Z]{2})?(?:;q=([0-9.]+))?/i', $header, $m, PREG_SET_ORDER );

		$weighted = [];
		foreach ( $m as $match ) {
			$code = strtolower( $match[1] );
			$q    = isset( $match[2] ) && $match[2] !== '' ? (float) $match[2] : 1.0;
			if ( ! isset( $weighted[ $code ] ) ) {
				$weighted[ $code ] = $q;
			}
		}

		arsort( $weighted );

		foreach ( array_keys( $weighted ) as $code ) {
			if ( OW_Languages::is_valid( $code ) ) {
				return $code;
			}
		}

		return OW_Languages::get_fallback();
	}

	// ── Rewrite Rules ─────────────────────────────────────────────────────────

	public function setup_rewrite_rules(): void {
		add_rewrite_tag( '%ow_lang%', '([a-z]{2,3})' );
		add_filter( 'rewrite_rules_array', [ $this, 'filter_rewrite_rules' ] );
	}

	public function filter_rewrite_rules( array $rules ): array {
		$langs = OW_Languages::get_all();
		$default = OW_Languages::get_default();
		unset( $langs[ $default ] );
		
		if ( empty( $langs ) ) {
			return $rules;
		}

		$lang_keys = array_keys( $langs );
		$lang_regex = implode( '|', array_map( function($l) { return preg_quote($l, '#'); }, $lang_keys ) );

		$new_rules = [];

		// Priority 1: Front page rule for all languages
		foreach ( $lang_keys as $lang ) {
			$new_rules[ '^' . preg_quote( $lang, '#' ) . '/?$' ] = 'index.php?ow_lang=' . $lang;
		}

		// Priority 2: Clone and prefix ALL existing WordPress rules
		foreach ( $rules as $match => $query ) {
			// Skip rules already prefixed with a lang group
			if ( preg_match( '#^\^?\(' . $lang_regex . '\)#', $match ) ) {
				continue;
			}

			// Do not prefix REST API, wp-admin or strictly technical internal endpoints
			if ( preg_match( '#^(wp-json|wp-admin)#', $match ) ) {
				continue;
			}

			// Shift all $matches[N] to $matches[N+1] because of the new capture group
			$new_query = preg_replace_callback( '/\$matches\[(\d+)\]/', function( $m ) {
				return '$matches[' . ( (int) $m[1] + 1 ) . ']';
			}, $query );

			// Append ow_lang
			$separator = ( strpos( $new_query, '?' ) !== false ) ? '&' : '?';
			$new_query .= $separator . 'ow_lang=$matches[1]';

			// Build prefixed match regex
			// Original match e.g. "product/([^/]+)/?$" -> "^(pl|fr)/product/([^/]+)/?$"
			$new_match = '^(' . $lang_regex . ')/' . ltrim( $match, '^' );

			$new_rules[ $new_match ] = $new_query;
		}

		return $new_rules + $rules;
	}

	/**
	 * Hook into `request` filter to make WordPress serve the correct content.
	 *
	 * When /pl/ is requested, ow_lang=pl is set but no pagename — WP's front
	 * page logic kicks in naturally (is_front_page() → true).
	 * When /pl/shop/ is requested, pagename=shop is set — WP finds and serves it.
	 */
	public function filter_request( array $query_vars ): array {
		if ( empty( $query_vars['ow_lang'] ) ) {
			return $query_vars;
		}

		$lang    = $query_vars['ow_lang'];
		$default = OW_Languages::get_default();

		if ( ! OW_Languages::is_valid( $lang ) || $lang === $default ) {
			return $query_vars;
		}

		// Store detected language in static cache
		self::$current_lang = $lang;

		// If only ow_lang is set (no pagename) → front page
		if ( empty( $query_vars['pagename'] ) && empty( $query_vars['page_id'] ) && empty( $query_vars['p'] ) && empty( $query_vars['name'] ) ) {
			// Tell WP to load the front page
			if ( 'page' === get_option( 'show_on_front' ) ) {
				$front_id = (int) get_option( 'page_on_front' );
				if ( $front_id ) {
					$query_vars['page_id'] = $front_id;
				}
			}
			// else: blog front page — nothing needed, WP handles it
		}

		return $query_vars;
	}

	// ── Redirect on first visit ───────────────────────────────────────────────

	public function redirect_if_needed(): void {
		if ( is_admin() ) return;

		$request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$default = OW_Languages::get_default();

		// Already on a language-prefixed URL — check status then set cookie
		$path     = trim( wp_parse_url( $request, PHP_URL_PATH ), '/' );
		$segments = explode( '/', $path );
		$first    = strtolower( $segments[0] ?? '' );
		if ( $first && OW_Languages::is_valid( $first ) ) {
			$status = OW_Languages::get_status( $first );

			// inactive → redirect everyone (even admins) to default
			if ( $status === OW_Languages::STATUS_INACTIVE ) {
				$this->set_lang_cookie( $default );
				wp_safe_redirect( home_url( '/' ), 302 );
				exit;
			}

			// pending → accessible only to admins
			if ( $status === OW_Languages::STATUS_PENDING && ! current_user_can( 'manage_options' ) ) {
				$this->set_lang_cookie( $default );
				wp_safe_redirect( home_url( '/' ), 302 );
				exit;
			}

			$this->set_lang_cookie( $first );
			return;
		}

		// On root / (default language territory) — always reset cookie to default.
		// Clears any stale cookie left from a previous non-default language visit.
		$cookie_lang = isset( $_COOKIE['ow_lang'] ) ? sanitize_key( wp_unslash( $_COOKIE['ow_lang'] ) ) : '';
		if ( ! empty( $cookie_lang ) ) {
			if ( $cookie_lang !== $default ) {
				$this->set_lang_cookie( $default );
			}
			return;
		}

		// No cookie at all — first visit. Detect preferred language and auto-redirect.
		$accept_lang = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		$detected    = ! empty( $accept_lang ) ? self::detect_from_header( $accept_lang ) : $default;

		$this->set_lang_cookie( $detected );

		if ( $detected === $default ) {
			return;
		}

		wp_safe_redirect( home_url( '/' . $detected . '/' ), 302 );
		exit;
	}

	private function set_lang_cookie( string $lang ): void {
		$cookie_lang = isset( $_COOKIE['ow_lang'] ) ? sanitize_key( wp_unslash( $_COOKIE['ow_lang'] ) ) : '';
		if ( $cookie_lang !== $lang ) {
			setcookie( 'ow_lang', $lang, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
	}
	// ── Dynamic Link Filtering ────────────────────────────────────────────────

	public function register_link_filters(): void {
		add_filter( 'home_url',       [ $this, 'filter_url' ], 10, 1 );
		add_filter( 'post_link',      [ $this, 'filter_url' ], 10, 1 );
		add_filter( 'page_link',      [ $this, 'filter_url' ], 10, 1 );
		add_filter( 'post_type_link', [ $this, 'filter_url' ], 10, 1 );
		add_filter( 'term_link',      [ $this, 'filter_url' ], 10, 1 );
		add_filter( 'wp_nav_menu_objects', [ $this, 'filter_nav_menu_items' ], 10, 1 );
		add_filter( 'render_block',        [ $this, 'filter_url' ], 10, 1 );
		add_filter( 'widget_text_content', [ $this, 'filter_url' ], 10, 1 );
	}

	public function filter_nav_menu_items( $items ) {
		if ( ! is_array( $items ) && ! is_object( $items ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( isset( $item->url ) ) {
				$item->url = $this->filter_url( $item->url );
			}
		}

		return $items;
	}

	public function filter_url( $url ) {
		static $running = false;
		if ( $running ) return $url;

		if ( ! is_string( $url ) ) return $url;

		$lang    = self::get_current_lang();
		$default = OW_Languages::get_default();

		if ( $lang === $default || is_admin() || wp_doing_ajax() || wp_is_json_request() || wp_doing_cron() ) {
			return $url;
		}

		$home_url = get_option( 'home' );
		if ( ! $home_url ) return $url;
		$home_url = rtrim( $home_url, '/' ) . '/';

		if ( strpos( $url, $home_url ) !== 0 ) {
			return $url;
		}

		$relative = substr( $url, strlen( $home_url ) );

		if ( preg_match( '#^wp-(admin|includes|content|json|login\.php|register\.php|cron\.php|activate\.php|signup\.php)#i', ltrim( $relative, '/' ) ) ) {
			return $url;
		}

		$segments = explode( '/', ltrim( $relative, '/' ) );
		$first    = strtolower( $segments[0] ?? '' );
		if ( $first && OW_Languages::is_valid( $first ) ) {
			return $url;
		}

		$running = true;
		$result  = $home_url . $lang . '/' . ltrim( $relative, '/' );
		$running = false;

		return $result;
	}

	// ── URL helpers ───────────────────────────────────────────────────────────

	public static function get_lang_url( string $lang, ?string $current_url = null ): string {
		$server_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url        = $current_url ?? home_url( $server_uri );
		$default = OW_Languages::get_default();

		// Strip any existing lang prefix from path (e.g. /pl/ → /)
		$url = preg_replace_callback(
			'#(https?://[^/]+)/([a-z]{2,3})((?:/.*)?$)#',
				function( $m ) {
					return OW_Languages::is_valid( $m[2] ) ? $m[1] . $m[3] : $m[0];
				},
				$url
		);

		// Ensure url ends with / at the path root
		if ( preg_match( '#https?://[^/]+$#', $url ) ) {
			$url .= '/';
		}

		if ( $lang === $default ) {
			return $url;
		}

		// Insert lang prefix after the domain
		return preg_replace( '#(https?://[^/]+)/#', '$1/' . $lang . '/', $url, 1 );
	}

	public static function flush_rules(): void {
		flush_rewrite_rules();
	}

	// ── hreflang ──────────────────────────────────────────────────────────────

	public function add_hreflang_tags(): void {
		$server_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path_only   = wp_parse_url( $server_uri, PHP_URL_PATH ) ?: '/';
		$current_url = home_url( $path_only );
		$default     = OW_Languages::get_default();

		// Only include active (public) languages in hreflang
		foreach ( OW_Languages::get_public() as $lang => $row ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s">' . "\n",
				esc_attr( str_replace( '_', '-', $row['locale'] ) ),
				esc_url( self::get_lang_url( $lang, $current_url ) )
			);
		}
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s">' . "\n",
			esc_url( self::get_lang_url( $default, $current_url ) )
		);
	}

	// ── Output HTML Rewriter ──────────────────────────────────────────────────

	public function register_output_rewriter(): void {
		add_action( 'template_redirect', function() {
			if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || wp_doing_cron() || is_customize_preview() ) {
				return;
			}

			ob_start( function( $html ) {
				$lang    = OW_Router::get_current_lang();
				$default = OW_Languages::get_default();

				if ( $lang === $default || empty( $html ) ) {
					return $html;
				}

				$home = get_option( 'home' );
				if ( ! $home ) {
					return $html;
				}

				return OW_Router::rewrite_html_links( $html, $lang, $home );
			} );
		}, 1 );
	}

	/**
	 * Scans HTML for <a> links and applies the language prefix natively using robust regex parsing.
	 * Evaluates `href` attributes regardless of placement within the <a> tag.
	 */
	public static function rewrite_html_links( string $html, string $lang, string $home ): string {
		$home_origin = rtrim( $home, '/' );

		// Pre-fetch exclusions to avoid querying the DB for every single link matched
		$raw_exclusions = get_option( 'ow_rewrite_exclusions', '' );
		$exclusions     = ! empty( $raw_exclusions ) ? array_filter( array_map( 'trim', explode( "\n", $raw_exclusions ) ) ) : [];

		return preg_replace_callback(
			'~<a\b(\s[^>]*)>~i',
			function( $tag_match ) use ( $lang, $home_origin, $exclusions ) {
				$attrs = $tag_match[1];

				if ( ! preg_match( '~\bhref=(["\'])([^"\']*)\1~i', $attrs, $href_match ) ) {
					return $tag_match[0];
				}

				$quote = $href_match[1];
				$href  = $href_match[2];

				// ── Skip conditions ──

				// 0. Explicit opt-out via data attribute (e.g. language switcher)
				if ( str_contains( $attrs, 'data-no-rewrite' ) ) {
					return $tag_match[0];
				}

				// 1. Empty or anchor-only
				if ( empty( $href ) || $href === '#' || str_starts_with( $href, '#' ) ) {
					return $tag_match[0];
				}

				// 2. Non-HTTP schemes
				if ( preg_match( '~^(mailto|tel|fax|javascript|data):~i', $href ) ) {
					return $tag_match[0];
				}

				// 3. Protocol-relative (known limitation — skip safely)
				if ( str_starts_with( $href, '//' ) ) {
					return $tag_match[0];
				}

				// 4. External URL (different origin) - protocol agnostic
				if ( str_starts_with( $href, 'http' ) ) {
					$href_host = wp_parse_url( $href, PHP_URL_HOST );
					$home_host = wp_parse_url( $home_origin, PHP_URL_HOST );
					if ( $href_host && $home_host && $href_host !== $home_host ) {
						return $tag_match[0];
					}
				}

				// 5. Normalise to path-only for remaining checks
				$path = str_starts_with( $href, 'http' )
					? wp_parse_url( $href, PHP_URL_PATH )
					: $href;
				$path = ltrim( $path ?? '', '/' );

				// 5.5 User-defined Exclusions
				foreach ( $exclusions as $rule ) {
					$rule_is_path    = str_starts_with( $rule, '/' );
					$rule_is_url     = str_starts_with( $rule, 'http' );
					$normalized_path = '/' . ltrim( $path, '/' );
					
					if ( 
						$href === $rule || 
						( $rule_is_path && ( $normalized_path === $rule || str_starts_with( $normalized_path, rtrim( $rule, '/' ) . '/' ) ) ) || 
						( $rule_is_url && ( $href === $rule || str_starts_with( $href, rtrim( $rule, '/' ) . '/' ) ) ) 
					) {
						return $tag_match[0];
					}
				}

				// 6. WP core / system paths
				if ( preg_match( '~^wp-(admin|content|includes|json|login\.php|register\.php|cron\.php|activate\.php|signup\.php)~i', $path ) ) {
					return $tag_match[0];
				}

				// 7. Feed and sitemap
				if ( preg_match( '~^(feed|sitemap|sitemap_index|rss|atom|xmlrpc\.php)~i', $path ) ) {
					return $tag_match[0];
				}

				// 8. WooCommerce AJAX
				if ( str_contains( $href, 'wc-ajax=' ) ) {
					return $tag_match[0];
				}

				// 9. Already has a valid lang prefix — check all active lang codes
				$first_segment = explode( '/', $path )[0] ?? '';
				if ( OW_Languages::is_valid( $first_segment ) ) {
					return $tag_match[0];
				}

				// ── Build new href ──
				if ( str_starts_with( $href, 'http' ) ) {
					// Absolute internal: reconstruct URL with lang pushed into the path
					$parsed = wp_parse_url( $href );
					$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '//';
					$host   = $parsed['host'] ?? '';
					$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
					$new_href = $scheme . $host . $port . '/' . $lang . '/' . $path;
					if ( isset( $parsed['query'] ) ) {
						$new_href .= '?' . $parsed['query'];
					}
					if ( isset( $parsed['fragment'] ) ) {
						$new_href .= '#' . $parsed['fragment'];
					}
				} else {
					// Relative: prepend /lang/
					$new_href = '/' . $lang . '/' . ltrim( $href, '/' );
				}

				$new_attrs = preg_replace(
					'~\bhref=(["\'])[^"\']*\1~i',
					'href=' . $quote . $new_href . $quote,
					$attrs,
					1
				);

				return '<a' . $new_attrs . '>';
			},
			$html
		);
	}
}
