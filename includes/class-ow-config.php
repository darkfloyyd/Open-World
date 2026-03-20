<?php
/**
 * Open World — Configuration Facade
 *
 * Thin static wrapper that delegates to OW_Languages (DB-backed).
 * Kept for backwards compatibility within the plugin, and as a central
 * place for non-language config (text domains to intercept, etc.)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Config {

	/** WordPress text domains whose strings are intercepted by the gettext filter */
	public static array $textdomains_to_intercept = [
		'default',
		'woocommerce',
		'generatepress-child',
	];

	// ── Language delegates (proxy to OW_Languages) ────────────────────────────

	public static function get_supported_languages(): array {
		return OW_Languages::get_all();
	}

	public static function get_default_lang(): string {
		return OW_Languages::get_default();
	}

	public static function get_fallback_lang(): string {
		return OW_Languages::get_fallback();
	}

	public static function get_locale( string $lang ): string {
		return OW_Languages::get_locale( $lang );
	}

	public static function get_lang_from_locale( string $locale ): string {
		return OW_Languages::locale_to_code( $locale );
	}

	public static function get_language_names(): array {
		return OW_Languages::get_names();
	}

	public static function get_language_flags(): array {
		return OW_Languages::get_flags();
	}

	public static function is_valid_lang( string $lang ): bool {
		return OW_Languages::is_valid( $lang );
	}

	public static function get_target_languages(): array {
		return OW_Languages::get_target_languages();
	}
}
