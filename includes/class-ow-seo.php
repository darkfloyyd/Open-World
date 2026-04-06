<?php
/**
 * Open World Translate — SEO Plugins Integration
 *
 * Passes popular SEO plugins' frontend output strings through WordPress gettext,
 * allowing the Smart Scanner to capture them and the translation engine to serve
 * translated versions dynamically.
 *
 * Supported plugins:
 *   - Yoast SEO
 *   - Rank Math SEO
 *   - All in One SEO (AIOSEO)
 *   - SEOPress
 *
 * Coverage:
 *   - Titles and meta descriptions
 *   - Open Graph (og:title, og:description)
 *   - Twitter Card (twitter:title, twitter:description)
 *   - Schema / JSON-LD structured data (name, description, headline fields)
 *
 * @package Open_World
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OW_SEO
 */
class OW_SEO {

	/**
	 * Keys in JSON-LD schema graphs that contain human-readable text.
	 * All other keys are left untouched (URLs, prices, dates, etc.).
	 *
	 * @var string[]
	 */
	private static array $schema_text_keys = [
		'name',
		'description',
		'alternateName',
		'headline',
		'caption',
		'jobTitle',
		'slogan',
		'disambiguatingDescription',
		'abstract',
	];

	/**
	 * Initialize all SEO hooks.
	 */
	public static function init() {
		$instance = new self();

		// ── Titles and Meta Descriptions ─────────────────────────────────────

		// Yoast SEO
		add_filter( 'wpseo_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'wpseo_metadesc', [ $instance, 'translate_meta' ], 999 );

		// Rank Math
		add_filter( 'rank_math/frontend/title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'rank_math/frontend/description', [ $instance, 'translate_meta' ], 999 );

		// All in One SEO (AIOSEO)
		add_filter( 'aioseo_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'aioseo_description', [ $instance, 'translate_meta' ], 999 );

		// SEOPress
		add_filter( 'seopress_titles_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'seopress_titles_desc', [ $instance, 'translate_meta' ], 999 );

		// ── Open Graph Tags ───────────────────────────────────────────────────

		// Yoast SEO OG
		add_filter( 'wpseo_opengraph_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'wpseo_opengraph_desc', [ $instance, 'translate_meta' ], 999 );

		// Rank Math OG
		add_filter( 'rank_math/frontend/og_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'rank_math/frontend/og_description', [ $instance, 'translate_meta' ], 999 );

		// AIOSEO OG
		add_filter( 'aioseo_og_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'aioseo_og_description', [ $instance, 'translate_meta' ], 999 );

		// SEOPress OG
		add_filter( 'seopress_social_og_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'seopress_social_og_desc', [ $instance, 'translate_meta' ], 999 );

		// ── Twitter Card Tags ─────────────────────────────────────────────────

		// Yoast SEO Twitter
		add_filter( 'wpseo_twitter_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'wpseo_twitter_description', [ $instance, 'translate_meta' ], 999 );

		// SEOPress Twitter
		add_filter( 'seopress_social_twitter_title', [ $instance, 'translate_meta' ], 999 );
		add_filter( 'seopress_social_twitter_desc', [ $instance, 'translate_meta' ], 999 );

		// ── Schema / JSON-LD Structured Data ──────────────────────────────────

		// Yoast SEO — full graph array
		add_filter( 'wpseo_schema_graph', [ $instance, 'translate_schema_graph' ], 999 );

		// Rank Math — full JSON-LD array
		add_filter( 'rank_math/json_ld', [ $instance, 'translate_schema_graph' ], 999 );

		// SEOPress — full schema array
		add_filter( 'seopress_schemas', [ $instance, 'translate_schema_graph' ], 999 );
		add_filter( 'seopress_pro_rich_snippets_schema', [ $instance, 'translate_schema_graph' ], 999 );
	}

	/**
	 * Translate a plain meta string (title, description, OG, Twitter).
	 *
	 * @param mixed $string The meta string.
	 * @return mixed
	 */
	public function translate_meta( $string ) {
		if ( empty( $string ) || ! is_string( $string ) ) {
			return $string;
		}

		// Running through the gettext filter lets our engine intercept,
		// record during Smart Scan, and return translated values on the frontend.
		return apply_filters( 'gettext', $string, $string, 'seo' );
	}

	/**
	 * Walk a JSON-LD structured data graph and translate human-readable fields.
	 *
	 * @param mixed $schema The schema graph (array of nodes or a single node).
	 * @return mixed
	 */
	public function translate_schema_graph( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		foreach ( $schema as $key => &$value ) {
			if ( is_array( $value ) ) {
				// Recurse into nested nodes.
				$value = $this->translate_schema_graph( $value );
			} elseif ( is_string( $value ) && ! empty( $value ) && in_array( $key, self::$schema_text_keys, true ) ) {
				// Skip URL-like values — they must not be translated.
				if ( 0 === strpos( $value, 'http' ) ) {
					continue;
				}
				$value = apply_filters( 'gettext', $value, $value, 'seo' );
			}
		}
		unset( $value );

		return $schema;
	}
}
