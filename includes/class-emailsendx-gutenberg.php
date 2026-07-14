<?php
/**
 * EmailSendX Sync — Block Editor (Gutenberg) adapter.
 *
 * The third builder adapter, and it covers TWO integrations at once: Spectra
 * is itself a Gutenberg block plugin, so native blocks appear in its editor
 * too — no separate Spectra adapter is needed.
 *
 * These are DYNAMIC blocks: `save()` returns null in JS and the front-end
 * markup comes from `render_callback()` here, which simply runs the shared
 * shortcode. That means a form placed as a block is byte-for-byte identical
 * to one placed with WPBakery or Elementor — one core, three adapters, no
 * duplicated rendering or styling.
 *
 * The attribute schema is declared ONCE (see attributes()) and handed to both
 * `register_block_type()` and the editor JS, so the PHP and JS definitions can
 * never drift apart — a classic source of "block validation failed" bugs.
 *
 * The editor script deliberately uses the global `wp.*` APIs rather than JSX,
 * so the plugin needs no npm/webpack build step to ship a block.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. If you find this signature in a
 * build that wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Gutenberg
 */
class EmailSendX_Gutenberg {

	/**
	 * Block category slug.
	 */
	const CATEGORY = 'emailsendx';

	/**
	 * Wire the adapter. Guarded on the block API existing at all.
	 */
	public function __construct() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // Pre-5.0 WordPress — nothing to do.
		}

		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );

		// Block category. `block_categories_all` is 5.8+; fall back for older.
		if ( version_compare( get_bloginfo( 'version' ), '5.8', '>=' ) ) {
			add_filter( 'block_categories_all', array( __CLASS__, 'register_category' ), 10, 1 );
		} else {
			add_filter( 'block_categories', array( __CLASS__, 'register_category' ), 10, 1 );
		}
	}

	/* ─── Category ─────────────────────────────────────────────────── */

	/**
	 * Seat an "EmailSendX" category near the top of the inserter.
	 *
	 * @param array $categories Existing categories.
	 * @return array
	 */
	public static function register_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}
		foreach ( $categories as $c ) {
			if ( isset( $c['slug'] ) && self::CATEGORY === $c['slug'] ) {
				return $categories; // Already there.
			}
		}

		$ours = array(
			array(
				'slug'  => self::CATEGORY,
				'title' => esc_html__( 'EmailSendX', 'emailsendx-sync' ),
			),
		);

		// Insert directly after "text" (the first, most-used category) rather
		// than appending to the bottom of a long inserter list.
		$out = array();
		foreach ( $categories as $category ) {
			$out[] = $category;
			if ( isset( $category['slug'] ) && 'text' === $category['slug'] ) {
				$out = array_merge( $out, $ours );
				$ours = array();
			}
		}
		return array_merge( $out, $ours );
	}

	/* ─── Attribute schema (single source of truth) ────────────────── */

	/**
	 * Shared style attributes — the same 16 controls the other builders expose.
	 * Every one maps 1:1 onto a shortcode attribute.
	 *
	 * @return array
	 */
	protected static function style_attributes() {
		$keys = array(
			'align',
			'width',
			'size',
			'spacing',
			'field_style',
			'radius',
			'labels',
			'field_bg',
			'field_color',
			'border_color',
			'accent',
			'button_color',
			'button_style',
			'button_align',
			'button_full',
			'text_color',
		);

		$attrs = array();
		foreach ( $keys as $key ) {
			$attrs[ $key ] = array(
				'type'    => 'string',
				'default' => '',
			);
		}
		return $attrs;
	}

	/**
	 * Full attribute schema per block.
	 *
	 * @param string $block 'form' | 'newsletter'.
	 * @return array
	 */
	public static function attributes( $block ) {
		$attrs = self::style_attributes();

		// Set only by the block's `example`, so the inserter's hover preview can
		// draw a static mock instead of round-tripping ServerSideRender (which
		// has no form/list to render and would show "No preview available").
		// Never reaches the shortcode — the render callbacks strip it.
		$attrs['_preview'] = array(
			'type'    => 'boolean',
			'default' => false,
		);

		if ( 'newsletter' === $block ) {
			foreach ( array( 'list', 'title', 'description', 'name', 'button', 'placeholder', 'success', 'consent' ) as $key ) {
				$attrs[ $key ] = array(
					'type'    => 'string',
					'default' => '',
				);
			}
			return $attrs;
		}

		$attrs['id'] = array(
			'type'    => 'string',
			'default' => '',
		);
		return $attrs;
	}

	/* ─── Registration ─────────────────────────────────────────────── */

	/**
	 * Register both dynamic blocks.
	 *
	 * @return void
	 */
	public static function register_blocks() {
		register_block_type(
			'emailsendx/form',
			array(
				'api_version'     => 2,
				'category'        => self::CATEGORY,
				'attributes'      => self::attributes( 'form' ),
				'render_callback' => array( __CLASS__, 'render_form' ),
				'editor_script'   => 'emailsendx-blocks',
				'style'           => 'emailsendx-forms',
			)
		);

		register_block_type(
			'emailsendx/newsletter',
			array(
				'api_version'     => 2,
				'category'        => self::CATEGORY,
				'attributes'      => self::attributes( 'newsletter' ),
				'render_callback' => array( __CLASS__, 'render_newsletter' ),
				'editor_script'   => 'emailsendx-blocks',
				'style'           => 'emailsendx-forms',
			)
		);
	}

	/* ─── Server-side render → the shared shortcodes ───────────────── */

	/**
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_form( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		unset( $attributes['_preview'] ); // Editor-only flag; never a shortcode att.

		if ( empty( $attributes['id'] ) ) {
			return self::placeholder( __( 'EmailSendX Form: choose a form in the block settings.', 'emailsendx-sync' ) );
		}
		return do_shortcode( EmailSendX_Builder_Data::build_shortcode( 'emailsendx_form', $attributes ) );
	}

	/**
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_newsletter( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		unset( $attributes['_preview'] ); // Editor-only flag; never a shortcode att.

		if ( empty( $attributes['list'] ) ) {
			return self::placeholder( __( 'EmailSendX Newsletter: choose a contact list in the block settings.', 'emailsendx-sync' ) );
		}
		return do_shortcode( EmailSendX_Builder_Data::build_shortcode( 'emailsendx_newsletter', $attributes ) );
	}

	/**
	 * Unconfigured-block hint. Editors see it; visitors see nothing.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	protected static function placeholder( $message ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<p style="padding:10px 14px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">'
			. esc_html( $message ) . '</p>';
	}

	/* ─── Editor assets ────────────────────────────────────────────── */

	/**
	 * Enqueue the editor script and hand it everything it needs: the attribute
	 * schema (so PHP stays the single source of truth), the picker options, and
	 * the contextual empty-state help.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		// The form stylesheet is normally registered on `wp_enqueue_scripts`,
		// which never fires in the admin — so the ServerSideRender preview
		// would render the real markup completely unstyled. Register it here
		// too and enqueue it into the editor.
		if ( class_exists( 'EmailSendX_Forms' ) && method_exists( 'EmailSendX_Forms', 'register_assets' ) ) {
			EmailSendX_Forms::register_assets();
			wp_enqueue_style( 'emailsendx-forms' );
		}

		wp_enqueue_script(
			'emailsendx-blocks',
			EMAILSENDX_SYNC_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			EMAILSENDX_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'emailsendx-blocks',
			'EmailSendXBlocks',
			array(
				'category'   => self::CATEGORY,
				'icon'       => EMAILSENDX_SYNC_URL . 'assets/img/icon-color.svg',
				'attributes' => array(
					'form'       => self::attributes( 'form' ),
					'newsletter' => self::attributes( 'newsletter' ),
				),
				'forms'      => EmailSendX_Builder_Data::select_options( 'forms' ),
				'lists'      => EmailSendX_Builder_Data::select_options( 'lists' ),
				'help'       => array(
					'forms' => wp_strip_all_tags( EmailSendX_Builder_Data::picker_description( 'forms' ) ),
					'lists' => wp_strip_all_tags( EmailSendX_Builder_Data::picker_description( 'lists' ) ),
				),
				'choices'    => array(
					'align'        => array( '' => __( 'Default', 'emailsendx-sync' ), 'left' => __( 'Left', 'emailsendx-sync' ), 'center' => __( 'Center', 'emailsendx-sync' ), 'right' => __( 'Right', 'emailsendx-sync' ) ),
					'width'        => array( '' => __( 'Default (480px)', 'emailsendx-sync' ), 'narrow' => __( 'Narrow (360px)', 'emailsendx-sync' ), 'wide' => __( 'Wide (640px)', 'emailsendx-sync' ), 'full' => __( 'Full width', 'emailsendx-sync' ) ),
					'size'         => array( '' => __( 'Default', 'emailsendx-sync' ), 'small' => __( 'Small', 'emailsendx-sync' ), 'large' => __( 'Large', 'emailsendx-sync' ) ),
					'spacing'      => array( '' => __( 'Default', 'emailsendx-sync' ), 'compact' => __( 'Compact', 'emailsendx-sync' ), 'relaxed' => __( 'Relaxed', 'emailsendx-sync' ) ),
					'field_style'  => array( '' => __( 'Outlined', 'emailsendx-sync' ), 'filled' => __( 'Filled', 'emailsendx-sync' ), 'underline' => __( 'Underline', 'emailsendx-sync' ) ),
					'radius'       => array( '' => __( 'Default', 'emailsendx-sync' ), 'rounded' => __( 'Rounded', 'emailsendx-sync' ), 'pill' => __( 'Pill', 'emailsendx-sync' ), 'square' => __( 'Square', 'emailsendx-sync' ) ),
					'labels'       => array( '' => __( 'Show', 'emailsendx-sync' ), 'hidden' => __( 'Hidden (placeholder only)', 'emailsendx-sync' ) ),
					'button_style' => array( '' => __( 'Solid', 'emailsendx-sync' ), 'outline' => __( 'Outline', 'emailsendx-sync' ) ),
					'button_align' => array( '' => __( 'Default', 'emailsendx-sync' ), 'left' => __( 'Left', 'emailsendx-sync' ), 'center' => __( 'Center', 'emailsendx-sync' ), 'right' => __( 'Right', 'emailsendx-sync' ) ),
				),
			)
		);
	}
}
