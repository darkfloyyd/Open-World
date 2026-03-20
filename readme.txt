=== Open World ===
Contributors: jakubmisiak
Tags: multilingual, translation, woocommerce, deepl, language switcher
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The first complete, free, and open-source multilingual solution for WordPress and WooCommerce. No per-word fees. No lock-ins.

== Description ==

Open World gives you full control over your website translations — built entirely on WordPress-native standards and engineered for performance. No premium upsells, no per-word charges, no bloated dependencies.

**Key Features:**

* 🚀 **Zero Database Overhead** — Uses WordPress native text domains. No extra columns, no schema changes to your existing tables.
* 🤖 **DeepL Auto-Translate** — Bulk-translate your entire site via DeepL API. Supports both Free and Pro plans, with automatic batch handling and rate-limit management.
* 🔍 **Smart Scanner** — Crawls your live frontend and captures only the strings actually rendered on your pages. Skip thousands of unused strings from plugins you barely use.
* ✏️ **Inline Translation Editor** — Translate strings visually while browsing your site. A sidebar slides in from the admin bar — just click any text and it jumps straight to the right string.
* 🛒 **Full WooCommerce Support** — Product titles, descriptions, categories, checkout fields, and order emails are all translated automatically per language.
* 🌐 **Hreflang & SEO-Friendly URLs** — Built-in language switchers, hreflang tags, and clean URL endpoints (e.g. `domain.com/es/`, `domain.com/pl/`).
* 🛡️ **SEO Plugin Integrations** — Translates titles, meta descriptions, Open Graph tags, Twitter Cards, and JSON-LD structured data for **Yoast SEO**, **Rank Math**, **All in One SEO**, and **SEOPress**. No extra setup required.
* 🔄 **Language Statuses** — Set each language as Active (public), Pending (admin-only preview), or Inactive (hidden).
* 📄 **PO Export** — Export translations as standard `.po` files at any time.
* 🔢 **Plural Forms** — Full plural rules for Polish, Russian, Arabic, Czech, Slovak, and 20+ other languages.

= Smart Scanner — How It Works =

The scanner sends authenticated internal requests to your frontend pages and captures every gettext call in real time — only the strings your site actually uses. Run "Clean Unused Strings" afterwards to keep the database lean.

= WooCommerce Integration =

Open World hooks into WooCommerce at every level: product edit screens get a tabbed translation UI, checkout field labels are translated dynamically, and order confirmation emails are sent in the customer's language.

= DeepL Integration =

Connect your DeepL Free or Pro API key and auto-translate your entire store in batches. Quota usage is shown in the plugin settings and cached to minimize API calls.

= SEO Plugin Integrations =

Open World automatically integrates with the most popular WordPress SEO plugins. When any of these plugins are active, Open World will intercept and translate their frontend output:

* **Yoast SEO** — Page titles, meta descriptions, og:title, og:description, twitter:title, twitter:description, Schema graph (JSON-LD)
* **Rank Math** — Titles, descriptions, og:title, og:description, JSON-LD structured data
* **All in One SEO (AIOSEO)** — Titles, descriptions, og:title, og:description
* **SEOPress** — Titles, descriptions, og:title, og:description, twitter:title, twitter:description, Schema arrays

No configuration needed. After a Smart Scan, simply translate your SEO strings in **Open World → Translations** and filter by source = `seo`.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/open-world/` or install via the WordPress plugin screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Open World → Languages** and add your target languages.
4. Go to **Settings** and run a **Smart Scan** to collect translatable strings.
5. Go to **Translations**, pick a language, and start translating — or enter a DeepL API key to auto-translate in bulk.

== Frequently Asked Questions ==

= Do I need a DeepL API key? =

No. DeepL is optional. You can translate everything manually using the built-in translation editor or the inline frontend editor. DeepL simply speeds up the process.

= Will this slow down my site? =

No. Translations are stored in a single custom table with indexed lookups and are cached using WordPress transients. The performance impact is negligible.

= Does it work with WooCommerce? =

Yes. Full support for product titles, descriptions, short descriptions, category names, checkout field labels, and order emails.

= Can I preview a translation before making it public? =

Yes. Set the language status to **Pending** — it will be visible only to logged-in administrators until you're ready to go live.

= How is this different from WPML or Polylang? =

Open World is 100% free with no premium tiers, stores translations in a single efficient table (not duplicated posts), and uses a smart frontend crawler instead of static file scanning to keep your database lean.

= What languages are supported? =

Any language can be added. Built-in plural rules are included for: English, German, French, Spanish, Italian, Polish, Russian, Ukrainian, Czech, Slovak, Arabic, Chinese, Japanese, Korean, Dutch, Portuguese (PT and BR), Swedish, Danish, Finnish, Norwegian, Turkish, Hungarian, and Romanian.

== Screenshots ==

1. Languages management screen — add and manage your target languages with status control.
2. Translation editor — paginated view with search, filter by source, and inline editing.
3. Inline frontend editor — translate any string by clicking it directly on the page.
4. WooCommerce product screen — per-language tabs for title, short description, and full description.
5. Settings screen — DeepL API configuration, Smart Scan, and usage statistics.

== Changelog ==

= 1.0.0 =
* Initial public release!
* Complete multilingual solution featuring Universal URL Routing for native permalink support across all post types.
* Deeply integrated WooCommerce support (products, checkout, emails).
* Robust Auto-Translate engine with DeepL and Google APIs featuring automatic network retry resiliency.
* Natively intercepts and translates metadata from Yoast SEO, Rank Math, AIOSEO, and SEOPress.