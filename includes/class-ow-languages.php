<?php
/**
 * Open World Translate — Language Registry
 *
 * Manages the list of languages from wp_ow_languages table.
 * Supports three visibility statuses:
 *   active  — visible on frontend for everyone
 *   pending — visible only for admins (translation phase before going live)
 *   inactive — hidden for everyone, no URL endpoints
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
class OW_Languages {

	const TABLE = 'ow_languages';

	const STATUS_ACTIVE   = 'active';
	const STATUS_PENDING  = 'pending';
	const STATUS_INACTIVE = 'inactive';

	/** Runtime cache */
	private static ?array  $all      = null;  // active + pending
	private static ?array  $public   = null;  // active only
	private static ?string $default  = null;
	private static ?string $fallback = null;
	private static ?string $source   = null;

	// ── Install ───────────────────────────────────────────────────────────────

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          int(11)      NOT NULL AUTO_INCREMENT,
			lang_code   varchar(10)  NOT NULL UNIQUE,
			locale      varchar(20)  NOT NULL,
			name        varchar(100) NOT NULL,
			flag        varchar(10)  DEFAULT NULL,
			is_default  tinyint(1)   NOT NULL DEFAULT 0,
			is_fallback tinyint(1)   NOT NULL DEFAULT 0,
			is_source   tinyint(1)   NOT NULL DEFAULT 0,
			is_active   tinyint(1)   NOT NULL DEFAULT 1,
			status      varchar(10)  NOT NULL DEFAULT 'active',
			sort_order  int(11)      NOT NULL DEFAULT 0,
			PRIMARY KEY (id)
		) ENGINE=InnoDB {$charset};";

		dbDelta( $sql );

		// Seed with WP's current language + English if not already seeded
		self::seed_defaults();
	}

	/**
	 * On first activation: seed the table with WordPress' current language + English.
	 */
	public static function seed_defaults(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Skip if already seeded
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$wp_locale = get_locale(); // e.g. 'nl_NL', 'de_DE', 'en_US'
		$wp_lang   = self::locale_to_code( $wp_locale ); // e.g. 'nl', 'de', 'en'

		$known = self::known_languages();

		// Insert WP language as default
		$wp_row = $known[ $wp_locale ] ?? [
			'name' => $wp_locale,
			'flag' => '',
		];

		$wpdb->insert( $table, [
			'lang_code'  => $wp_lang,
			'locale'     => $wp_locale,
			'name'       => $wp_row['name'],
			'flag'       => $wp_row['flag'],
			'is_default' => 1,
			'is_fallback'=> 0,
			'is_source'  => 1,
			'is_active'  => 1,
			'status'     => self::STATUS_ACTIVE,
			'sort_order' => 0,
		] );

		// Insert English as fallback (if WP lang isn't already English)
		if ( $wp_lang !== 'en' ) {
			$en = $known['en_US'] ?? [ 'name' => 'English', 'flag' => '🇬🇧' ];
			$wpdb->insert( $table, [
				'lang_code'  => 'en',
				'locale'     => 'en_US',
				'name'       => $en['name'],
				'flag'       => $en['flag'],
				'is_default' => 0,
				'is_fallback'=> 1,
				'is_active'  => 1,
				'status'     => self::STATUS_ACTIVE,
				'sort_order' => 1,
			] );
		} else {
			// WP is already English — mark it as fallback too
			$wpdb->update( $table, [ 'is_fallback' => 1 ], [ 'lang_code' => 'en' ] );
		}
	}

	// ── Getters ───────────────────────────────────────────────────────────────

	/**
	 * All non-inactive languages: active + pending.
	 * Used internally for routing, translation engine, and admin access.
	 */
	public static function get_all(): array {
		if ( self::$all !== null ) return self::$all;

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status != 'inactive' ORDER BY sort_order, id",
			ARRAY_A
		);

		self::$all = [];
		foreach ( $rows as $r ) {
			self::$all[ $r['lang_code'] ] = $r;
		}

		return self::$all;
	}

	/**
	 * Public languages only: status = 'active'.
	 * Used for frontend switcher shortcodes and hreflang tags.
	 */
	public static function get_public(): array {
		if ( self::$public !== null ) return self::$public;

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status = 'active' ORDER BY sort_order, id",
			ARRAY_A
		);

		self::$public = [];
		foreach ( $rows as $r ) {
			self::$public[ $r['lang_code'] ] = $r;
		}

		return self::$public;
	}

	/**
	 * Get ALL languages including inactive (admin-only view).
	 */
	public static function get_all_including_inactive(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY sort_order, id",
			ARRAY_A
		);

		$result = [];
		foreach ( $rows as $r ) {
			$result[ $r['lang_code'] ] = $r;
		}
		return $result;
	}

	public static function get_status( string $lang ): string {
		return self::get_all()[ $lang ]['status'] ?? self::get_all_including_inactive()[ $lang ]['status'] ?? self::STATUS_INACTIVE;
	}

	public static function get_default(): string {
		if ( self::$default !== null ) return self::$default;

		global $wpdb;
		$table         = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$default = $wpdb->get_var( "SELECT lang_code FROM {$table} WHERE is_default = 1 LIMIT 1" )
		                 ?? 'en';
		return self::$default;
	}

	public static function get_fallback(): string {
		if ( self::$fallback !== null ) return self::$fallback;

		global $wpdb;
		$table          = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$fallback = $wpdb->get_var( "SELECT lang_code FROM {$table} WHERE is_fallback = 1 LIMIT 1" )
		                  ?? 'en';
		return self::$fallback;
	}

	public static function get_target_languages(): array {
		$source = self::get_source();
		return array_keys( array_filter( self::get_all(), fn( $r ) => $r['lang_code'] !== $source ) );
	}

	public static function get_source(): string {
		if ( self::$source !== null ) return self::$source;

		global $wpdb;
		$table        = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$source = $wpdb->get_var( "SELECT lang_code FROM {$table} WHERE is_source = 1 LIMIT 1" )
		               ?? self::get_default();
		return self::$source;
	}

	public static function set_source( string $lang_code ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( ! self::is_valid( $lang_code ) ) return false;

		$wpdb->update( $table, [ 'is_source' => 0 ], [ 'is_source' => 1 ] );
		$wpdb->update( $table, [ 'is_source' => 1 ], [ 'lang_code' => $lang_code ] );

		self::reset_cache();
		return true;
	}

	public static function is_valid( string $lang ): bool {
		return isset( self::get_all()[ $lang ] );
	}

	public static function get_locale( string $lang ): string {
		return self::get_all()[ $lang ]['locale'] ?? 'en_US';
	}

	public static function get_name( string $lang ): string {
		return self::get_all()[ $lang ]['name'] ?? $lang;
	}

	public static function get_flag( string $lang ): string {
		return self::get_all()[ $lang ]['flag'] ?? '';
	}

	public static function get_names(): array {
		return array_map( fn( $r ) => $r['name'], self::get_all() );
	}

	public static function get_flags(): array {
		return array_map( fn( $r ) => $r['flag'] ?? '', self::get_all() );
	}

	public static function locale_to_code( string $locale ): string {
		// e.g. 'nl_NL' => 'nl', 'en_US' => 'en', 'zh_TW' => 'zh'
		$parts = explode( '_', $locale );
		return strtolower( $parts[0] );
	}

	// ── Admin Actions ─────────────────────────────────────────────────────────

	public static function add( string $lang_code, string $locale, string $name, string $flag = '' ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$result = $wpdb->insert( $table, [
			'lang_code'  => $lang_code,
			'locale'     => $locale,
			'name'       => $name,
			'flag'       => $flag,
			'is_default' => 0,
			'is_fallback'=> 0,
			'is_active'  => 1,
			'status'     => self::STATUS_ACTIVE,
			'sort_order' => $count,
		] );

		self::reset_cache();
		return $result !== false;
	}

	public static function remove( string $lang_code ): bool {
		global $wpdb;
		$lang_table = $wpdb->prefix . self::TABLE;
		$tr_table   = $wpdb->prefix . 'ow_translations';

		// Cannot remove the default URL language or the source language
		if ( $lang_code === self::get_default() ) return false;
		if ( $lang_code === self::get_source() )  return false;

		// Delete all translations for this language
		$wpdb->delete( $tr_table, [ 'lang' => $lang_code ] );

		// Delete the language entry itself
		$result = $wpdb->delete( $lang_table, [ 'lang_code' => $lang_code ] );
		self::reset_cache();
		return $result !== false && $result > 0;
	}

	public static function set_status( string $lang_code, string $status ): bool {
		if ( ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_INACTIVE ], true ) ) {
			return false;
		}

		// Default and source languages must stay active
		if ( $status !== self::STATUS_ACTIVE ) {
			if ( $lang_code === self::get_default() ) return false;
			if ( $lang_code === self::get_source() )  return false;
		}

		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$result = $wpdb->update( $table, [ 'status' => $status ], [ 'lang_code' => $lang_code ] );

		self::reset_cache();
		return $result !== false;
	}

	public static function set_default( string $lang_code ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( ! self::is_valid( $lang_code ) ) return false;

		$wpdb->update( $table, [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
		$wpdb->update( $table, [ 'is_default' => 1, 'status' => self::STATUS_ACTIVE ], [ 'lang_code' => $lang_code ] );

		self::reset_cache();
		return true;
	}

	public static function set_fallback( string $lang_code ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$wpdb->update( $table, [ 'is_fallback' => 0 ], [ 'is_fallback' => 1 ] );
		$wpdb->update( $table, [ 'is_fallback' => 1 ], [ 'lang_code' => $lang_code ] );

		self::reset_cache();
		return true;
	}

	public static function ensure_table_exists(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			self::install();
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			// Add is_source column if missing (upgrade from pre-Phase-4)
			if ( ! in_array( 'is_source', $cols, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN is_source tinyint(1) NOT NULL DEFAULT 0 AFTER is_fallback" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "UPDATE {$table} SET is_source = 1 WHERE is_default = 1 LIMIT 1" );
			}
			// Add status column if missing
			if ( ! in_array( 'status', $cols, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN status varchar(10) NOT NULL DEFAULT 'active' AFTER is_active" );
				// Migrate: is_active=0 → inactive, is_active=1 → active
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "UPDATE {$table} SET status = 'active' WHERE is_active = 1" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "UPDATE {$table} SET status = 'inactive' WHERE is_active = 0" );
			}
		}
	}

	private static function reset_cache(): void {
		self::$all      = null;
		self::$public   = null;
		self::$default  = null;
		self::$fallback = null;
		self::$source   = null;
	}

	// ── Plural Rules ──────────────────────────────────────────────────────────

	/**
	 * Get the plural form index for a given count and locale.
	 */
	public static function get_plural_index( string $lang, int $count ): int {
		// Standard 2-form: n != 1 → form 1 (English, German, Dutch, Spanish, French…)
		$two_form = [ 'en', 'de', 'nl', 'es', 'fr', 'it', 'pt', 'sv', 'da', 'fi', 'nb', 'hu' ];
		if ( in_array( $lang, $two_form, true ) ) {
			return $count !== 1 ? 1 : 0;
		}

		// Polish: 3 forms
		if ( $lang === 'pl' ) {
			if ( $count === 1 ) return 0;
			if ( $count % 10 >= 2 && $count % 10 <= 4 && ( $count % 100 < 10 || $count % 100 >= 20 ) ) return 1;
			return 2;
		}

		// Russian / Ukrainian
		if ( in_array( $lang, [ 'ru', 'uk' ], true ) ) {
			if ( $count % 10 === 1 && $count % 100 !== 11 ) return 0;
			if ( $count % 10 >= 2 && $count % 10 <= 4 && ( $count % 100 < 10 || $count % 100 >= 20 ) ) return 1;
			return 2;
		}

		// Czech / Slovak
		if ( in_array( $lang, [ 'cs', 'sk' ], true ) ) {
			if ( $count === 1 ) return 0;
			if ( $count >= 2 && $count <= 4 ) return 1;
			return 2;
		}

		// Arabic: 6 forms
		if ( $lang === 'ar' ) {
			if ( $count === 0 ) return 0;
			if ( $count === 1 ) return 1;
			if ( $count === 2 ) return 2;
			if ( $count % 100 >= 3 && $count % 100 <= 10 ) return 3;
			if ( $count % 100 >= 11 ) return 4;
			return 5;
		}

		// Default fallback: 2-form
		return $count !== 1 ? 1 : 0;
	}

	// ── Known Language Data ───────────────────────────────────────────────────

	public static function known_languages(): array {
		return [

			// ── Western Europe ─────────────────────────────────────────────────
			'en_US' => [ 'name' => 'English',          'flag' => '��🇸' ],
			'en_GB' => [ 'name' => 'English (UK)',     'flag' => '🇬🇧' ],
			'de_DE' => [ 'name' => 'Deutsch',          'flag' => '🇩🇪' ],
			'fr_FR' => [ 'name' => 'Français',         'flag' => '🇫🇷' ],
			'es_ES' => [ 'name' => 'Español',          'flag' => '🇪🇸' ],
			'it_IT' => [ 'name' => 'Italiano',         'flag' => '🇮🇹' ],
			'nl_NL' => [ 'name' => 'Nederlands',       'flag' => '🇳🇱' ],
			'pt_PT' => [ 'name' => 'Português',        'flag' => '🇵🇹' ],
			'ca'    => [ 'name' => 'Català',           'flag' => '🏳️' ],
			'gl_ES' => [ 'name' => 'Galego',           'flag' => '🏳️' ],
			'eu'    => [ 'name' => 'Euskara',          'flag' => '🏳️' ],
			'cy'    => [ 'name' => 'Cymraeg',          'flag' => '🏴󠁧󠁢󠁷󠁬󠁳󠁿' ],

			// ── Nordic ─────────────────────────────────────────────────────────
			'sv_SE' => [ 'name' => 'Svenska',          'flag' => '🇸🇪' ],
			'da_DK' => [ 'name' => 'Dansk',            'flag' => '🇩🇰' ],
			'nb_NO' => [ 'name' => 'Norsk',            'flag' => '🇳🇴' ],
			'fi'    => [ 'name' => 'Suomi',            'flag' => '🇫🇮' ],
			'is_IS' => [ 'name' => 'Íslenska',         'flag' => '🇮🇸' ],

			// ── Baltic ─────────────────────────────────────────────────────────
			'lt_LT' => [ 'name' => 'Lietuvių',         'flag' => '🇱🇹' ],
			'lv'    => [ 'name' => 'Latviešu',         'flag' => '🇱🇻' ],
			'et'    => [ 'name' => 'Eesti',            'flag' => '🇪🇪' ],

			// ── Central Europe ─────────────────────────────────────────────────
			'pl_PL' => [ 'name' => 'Polski',           'flag' => '🇵🇱' ],
			'cs_CZ' => [ 'name' => 'Čeština',          'flag' => '🇨🇿' ],
			'sk_SK' => [ 'name' => 'Slovenčina',       'flag' => '🇸🇰' ],
			'hu_HU' => [ 'name' => 'Magyar',           'flag' => '🇭🇺' ],
			'ro_RO' => [ 'name' => 'Română',           'flag' => '🇷🇴' ],

			// ── Eastern Europe & Slavic ────────────────────────────────────────
			'ru_RU' => [ 'name' => 'Русский',          'flag' => '🇷🇺' ],
			'uk_UA' => [ 'name' => 'Українська',       'flag' => '🇺🇦' ],
			'bg_BG' => [ 'name' => 'Български',        'flag' => '🇧🇬' ],
			'sr_RS' => [ 'name' => 'Српски',           'flag' => '🇷🇸' ],
			'hr'    => [ 'name' => 'Hrvatski',         'flag' => '🇭🇷' ],
			'bs_BA' => [ 'name' => 'Bosanski',         'flag' => '🇧🇦' ],
			'sl_SI' => [ 'name' => 'Slovenščina',      'flag' => '🇸🇮' ],
			'mk_MK' => [ 'name' => 'Македонски',       'flag' => '🇲🇰' ],

			// ── Southern Europe ────────────────────────────────────────────────
			'el'    => [ 'name' => 'Ελληνικά',         'flag' => '🇬🇷' ],
			'sq'    => [ 'name' => 'Shqip',            'flag' => '🇦🇱' ],
			'mt_MT' => [ 'name' => 'Malti',            'flag' => '🇲🇹' ],

			// ── Middle East ────────────────────────────────────────────────────
			'ar'    => [ 'name' => 'العربية',           'flag' => '🇸🇦' ],
			'he_IL' => [ 'name' => 'עברית',             'flag' => '🇮🇱' ],
			'fa_IR' => [ 'name' => 'فارسی',             'flag' => '��🇷' ],
			'ur'    => [ 'name' => 'اردو',              'flag' => '🇵🇰' ],
			'tr_TR' => [ 'name' => 'Türkçe',           'flag' => '🇹🇷' ],

			// ── Caucasus & Central Asia ────────────────────────────────────────
			'az'    => [ 'name' => 'Azərbaycan',       'flag' => '🇦🇿' ],
			'ka_GE' => [ 'name' => 'ქართული',          'flag' => '🇬🇪' ],
			'hy'    => [ 'name' => 'Հայերեն',          'flag' => '🇦🇲' ],
			'kk'    => [ 'name' => 'Қазақша',          'flag' => '🇰🇿' ],
			'uz_UZ' => [ 'name' => 'Oʻzbekcha',        'flag' => '🇺🇿' ],
			'tg'    => [ 'name' => 'Тоҷикӣ',           'flag' => '🇹🇯' ],
			'mn'    => [ 'name' => 'Монгол',           'flag' => '��🇳' ],

			// ── South Asia ─────────────────────────────────────────────────────
			'hi_IN' => [ 'name' => 'हिन्दी',            'flag' => '🇮��' ],
			'bn_BD' => [ 'name' => 'বাংলা',             'flag' => '🇧🇩' ],
			'mr'    => [ 'name' => 'मराठी',             'flag' => '🇮🇳' ],
			'ta_IN' => [ 'name' => 'தமிழ்',             'flag' => '🇮🇳' ],
			'ne_NP' => [ 'name' => 'नेपाली',            'flag' => '🇳🇵' ],
			'si_LK' => [ 'name' => 'සිංහල',            'flag' => '🇱🇰' ],

			// ── East Asia ──────────────────────────────────────────────────────
			'zh_CN' => [ 'name' => '中文 (简体)',         'flag' => '🇨🇳' ],
			'zh_TW' => [ 'name' => '中文 (繁體)',         'flag' => '🇹🇼' ],
			'ja'    => [ 'name' => '日本語',              'flag' => '🇯🇵' ],
			'ko_KR' => [ 'name' => '한국어',              'flag' => '🇰🇷' ],

			// ── Southeast Asia ─────────────────────────────────────────────────
			'id_ID' => [ 'name' => 'Bahasa Indonesia', 'flag' => '🇮🇩' ],
			'ms_MY' => [ 'name' => 'Bahasa Melayu',   'flag' => '🇲🇾' ],
			'vi'    => [ 'name' => 'Tiếng Việt',       'flag' => '🇻🇳' ],
			'th'    => [ 'name' => 'ภาษาไทย',           'flag' => '🇹🇭' ],
			'tl'    => [ 'name' => 'Filipino',         'flag' => '🇵🇭' ],
			'my'    => [ 'name' => 'မြန်မာဘာသာ',       'flag' => '🇲🇲' ],

			// ── Americas ───────────────────────────────────────────────────────
			'pt_BR' => [ 'name' => 'Português (BR)',   'flag' => '🇧🇷' ],
			'es_MX' => [ 'name' => 'Español (MX)',     'flag' => '🇲🇽' ],
			'es_AR' => [ 'name' => 'Español (AR)',     'flag' => '🇦🇷' ],

			// ── Africa ─────────────────────────────────────────────────────────
			'sw'    => [ 'name' => 'Kiswahili',        'flag' => '🇰🇪' ],
			'af'    => [ 'name' => 'Afrikaans',        'flag' => '🇿🇦' ],
			'am'    => [ 'name' => 'አማርኛ',             'flag' => '🇪🇹' ],
			'yo_NG' => [ 'name' => 'Yorùbá',           'flag' => '🇳🇬' ],
		];

	}
}
// phpcs:enable
