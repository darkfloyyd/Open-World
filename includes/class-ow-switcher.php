<?php
/**
 * Open World Translate — Language Switcher Widget + Shortcode
 *
 * Shortcode: [ow_language_switcher style="dropdown|flags|codes|list" show_flags="true" show_names="true"]
 * Styles:
 *   dropdown — expandable dropdown (default)
 *   flags    — flag emojis inline, horizontal
 *   codes    — language codes inline (e.g. PL EN DE)
 *   list     — flags + names inline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Switcher {

	public static function render( array $args = [] ): string {
		$style      = $args['style']      ?? 'dropdown';
		$show_flag  = (bool) ( $args['show_flags'] ?? true );
		$show_name  = (bool) ( $args['show_names'] ?? true );

		$current  = OW_Router::get_current_lang();
		$is_admin = current_user_can( 'manage_options' );

		// Admins see active + pending (with badge), guests see active only
		$all = $is_admin ? OW_Languages::get_all() : OW_Languages::get_public();

		ob_start();

		if ( $style === 'dropdown' ) {
			$flag_label = $show_flag ? ( OW_Languages::get_flag( $current ) . ' ' ) : '';
			$name_label = $show_name ? OW_Languages::get_name( $current ) : strtoupper( $current );
			?>
			<div class="ow-switcher ow-switcher--dropdown" role="navigation" aria-label="<?php echo  esc_attr__( 'Language', 'open-world-translate' ) ?>">
				<button class="ow-switcher__current" aria-haspopup="true" aria-expanded="false">
					<?php echo  esc_html( $flag_label . $name_label ) ?> <span aria-hidden="true">▾</span>
				</button>
				<ul class="ow-switcher__menu" role="menu">
					<?php foreach ( $all as $lang => $row ): ?>
					<?php if ( $lang === $current ) continue; ?>
					<li role="none">
						<a role="menuitem" data-no-rewrite="1" href="<?php echo  esc_url( OW_Router::get_lang_url( $lang ) ) ?>" hreflang="<?php echo  esc_attr( str_replace( '_', '-', $row['locale'] ) ) ?>">
							<?php if ( $show_flag ) echo esc_html( $row['flag'] ?? '' ) . ' '; ?>
							<?php if ( $show_name ) echo esc_html( $row['name'] ); else echo esc_html( strtoupper( $lang ) ); ?>
						</a>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php

		} elseif ( $style === 'codes' ) {
			// Compact code-only: PL  EN  DE  NL  …
			?>
			<nav class="ow-switcher ow-switcher--codes" aria-label="<?php echo esc_attr__( 'Language', 'open-world-translate' ); ?>">
			<?php
			foreach ( $all as $lang => $row ) {
				$active = $lang === $current ? ' ow-switcher__link--active' : '';
				printf(
					'<a class="ow-switcher__link%s" data-no-rewrite="1" href="%s" hreflang="%s" title="%s">%s</a>',
					esc_attr( $active ),
					esc_url( OW_Router::get_lang_url( $lang ) ),
					esc_attr( str_replace( '_', '-', $row['locale'] ) ),
					esc_attr( $row['name'] ),
					esc_html( strtoupper( $lang ) )
				);
			}
			?>
			</nav>
			<?php

		} else {
			// flags or list style
			?>
			<nav class="ow-switcher ow-switcher--<?php echo esc_attr( $style ); ?>" aria-label="<?php echo esc_attr__( 'Language', 'open-world-translate' ); ?>">
			<?php
			foreach ( $all as $lang => $row ) {
				$active = $lang === $current ? ' ow-switcher__link--active' : '';
				printf(
					'<a class="ow-switcher__link%s" data-no-rewrite="1" href="%s" hreflang="%s" title="%s">%s%s</a>',
					esc_attr( $active ),
					esc_url( OW_Router::get_lang_url( $lang ) ),
					esc_attr( str_replace( '_', '-', $row['locale'] ) ),
					esc_attr( $row['name'] ),
					$show_flag ? esc_html( $row['flag'] ?? '' ) : '',
					$show_name ? ' <span>' . esc_html( $row['name'] ) . '</span>' : ''
				);
			}
			?>
			</nav>
			<?php
		}

		return ob_get_clean();
	}

	public static function shortcode( array $atts ): string {
		$atts = shortcode_atts( [
			'style'      => 'dropdown',
			'show_flags' => 'true',
			'show_names' => 'true',
		], $atts, 'ow_language_switcher' );

		$atts['show_flags'] = filter_var( $atts['show_flags'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_names'] = filter_var( $atts['show_names'], FILTER_VALIDATE_BOOLEAN );

		return self::render( $atts );
	}

	public static function register(): void {
		add_shortcode( 'ow_language_switcher', [ __CLASS__, 'shortcode' ] );
		add_action( 'widgets_init', [ __CLASS__, 'register_widget' ] );
		add_action( 'wp_head', [ __CLASS__, 'inline_styles' ] );
	}

	public static function register_widget(): void {
		register_widget( 'OW_Switcher_Widget' );
	}

	public static function inline_styles(): void {
		$css = '
		.ow-switcher--dropdown { position: relative; display: inline-block; }
		.ow-switcher__current { background: none; border: 1px solid rgba(255,255,255,.3); color: inherit; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: .9rem; }
		.ow-switcher__menu { display: none; position: absolute; right: 0; top: 100%; min-width: 150px; background: #fff; border: 1px solid #ddd; border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,.15); list-style: none; margin: 4px 0 0; padding: 4px 0; z-index: 9999; }
		.ow-switcher--dropdown:hover .ow-switcher__menu,
		.ow-switcher__current[aria-expanded="true"] + .ow-switcher__menu { display: block; }
		.ow-switcher__menu li a { display: block; padding: 8px 14px; text-decoration: none; color: #333; font-size: .9rem; white-space: nowrap; }
		.ow-switcher__menu li a:hover { background: #f5f5f5; color: #2271b1; }
		/* codes style */
		.ow-switcher--codes { display: inline-flex; gap: 2px; }
		.ow-switcher--codes .ow-switcher__link { display: inline-block; padding: 4px 8px; text-decoration: none; color: inherit; font-weight: 500; font-size: .85rem; border-radius: 3px; transition: background .15s, color .15s; text-transform: uppercase; letter-spacing: .03em; }
		.ow-switcher--codes .ow-switcher__link:hover { background: rgba(34,113,177,.1); color: #2271b1; }
		.ow-switcher--codes .ow-switcher__link--active { font-weight: 700; color: #2271b1; background: rgba(34,113,177,.08); }
		/* flags + list styles */
		.ow-switcher--flags .ow-switcher__link,
		.ow-switcher--list .ow-switcher__link { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; text-decoration: none; color: inherit; border-radius: 3px; transition: background .15s; }
		.ow-switcher__link--active { font-weight: 700; }
		';
		wp_register_style( 'ow-switcher', false );
		wp_enqueue_style( 'ow-switcher' );
		wp_add_inline_style( 'ow-switcher', $css );
	}
}

// ── Widget ────────────────────────────────────────────────────────────────────

class OW_Switcher_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct( 'ow_switcher', __( 'Language Switcher', 'open-world-translate' ), [
			'description' => __( 'Open World language switcher', 'open-world-translate' ),
		] );
	}

	public function widget( $args, $instance ): void {
		echo $args['before_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$args_arr = [
			'style'      => $instance['style'] ?? 'dropdown',
			'show_flags' => $instance['show_flags'] ?? true,
			'show_names' => $instance['show_names'] ?? true,
		];
		echo OW_Switcher::render( $args_arr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget'] ?? '';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		$style = $instance['style'] ?? 'dropdown';
		?>
		<p>
			<label for="<?php echo  esc_attr( $this->get_field_id( 'style' ) ) ?>"><?php echo  esc_html__( 'Style', 'open-world-translate' ) ?></label>
			<select name="<?php echo  esc_attr( $this->get_field_name( 'style' ) ) ?>" id="<?php echo  esc_attr( $this->get_field_id( 'style' ) ) ?>">
				<option value="dropdown" <?php echo  selected( $style, 'dropdown', false ) ?>>Dropdown</option>
				<option value="flags"    <?php echo  selected( $style, 'flags',    false ) ?>>Flags only</option>
				<option value="codes"    <?php echo  selected( $style, 'codes',    false ) ?>>Codes (PL EN DE)</option>
				<option value="list"     <?php echo  selected( $style, 'list',     false ) ?>>List (flags + names)</option>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return [
			'style'      => sanitize_key( $new_instance['style'] ?? 'dropdown' ),
			'show_flags' => (bool) ( $new_instance['show_flags'] ?? true ),
			'show_names' => (bool) ( $new_instance['show_names'] ?? true ),
		];
	}
}
