<?php
/**
 * Open World Translate — Gettext Filter Engine
 *
 * Intercepts WordPress gettext calls and returns DB translations.
 *
 * Key improvements over v1:
 *  - sprintf() passthrough: translated strings containing %s / %d receive
 *    their values from the original WordPress sprintf() chain transparently.
 *  - Full plural support via OW_DB::get_plural() + OW_Languages::get_plural_index()
 *  - Default language check uses DB-backed OW_Languages::get_default()
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Engine {

	public function register_filters(): void {
		add_filter( 'gettext',              [ $this, 'filter_gettext' ],     1, 3 );
		add_filter( 'gettext_with_context', [ $this, 'filter_gettext_ctx' ], 1, 4 );
		add_filter( 'ngettext',             [ $this, 'filter_ngettext' ],    1, 5 );
		add_filter( 'ngettext_with_context',[ $this, 'filter_ngettext_ctx' ],1, 6 );
		add_filter( 'determine_locale',     [ $this, 'determine_locale' ],  20 );
	}

	// ── Singular ──────────────────────────────────────────────────────────────

	public function filter_gettext( string $translation, string $text, string $domain ): string {
		$lang = OW_Router::get_current_lang();

		// Skip translation for source language — msgids ARE the source language
		if ( $lang === OW_Languages::get_source() ) return $translation;
		if ( ! in_array( $domain, OW_Config::$textdomains_to_intercept, true ) ) return $translation;

		$translated = OW_DB::get_translation( $lang, $text, $domain );
		return $translated ?? $translation;
	}

	public function filter_gettext_ctx( string $translation, string $text, string $context, string $domain ): string {
		$lang = OW_Router::get_current_lang();

		if ( $lang === OW_Languages::get_source() ) return $translation;
		if ( ! in_array( $domain, OW_Config::$textdomains_to_intercept, true ) ) return $translation;

		$translated = OW_DB::get_translation( $lang, $text, $domain, $context );
		return $translated ?? $translation;
	}


	// ── Plural ────────────────────────────────────────────────────────────────

	/**
	 * ngettext filter — handles plural forms with language-specific rules.
	 *
	 * WordPress calls: sprintf( _n( 'One item', '%d items', $count ), $count )
	 * We intercept _n() to return the correct translated plural form.
	 * The translated string should contain %d if the original does — sprintf()
	 * runs AFTER our filter returns, so placeholders stay intact.
	 */
	public function filter_ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
		$lang = OW_Router::get_current_lang();

		if ( $lang === OW_Languages::get_source() ) return $translation;
		if ( ! in_array( $domain, OW_Config::$textdomains_to_intercept, true ) ) return $translation;

		$translated = OW_DB::get_plural( $lang, $single, $plural, $number, $domain );

		if ( $translated !== null ) return $translated;

		$singular_t = OW_DB::get_translation( $lang, $single, $domain );
		return $singular_t ?? $translation;
	}

	public function filter_ngettext_ctx( string $translation, string $single, string $plural, int $number, string $context, string $domain ): string {
		$lang = OW_Router::get_current_lang();

		if ( $lang === OW_Languages::get_source() ) return $translation;
		if ( ! in_array( $domain, OW_Config::$textdomains_to_intercept, true ) ) return $translation;

		$translated = OW_DB::get_plural( $lang, $single, $plural, $number, $domain );
		return $translated ?? $translation;
	}

	// ── Locale ────────────────────────────────────────────────────────────────

	public function determine_locale( string $locale ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $locale; // Do not hijack admin dashboard language
		}
		$lang = OW_Router::get_current_lang();
		return OW_Languages::get_locale( $lang ) ?: $locale;
	}
}

