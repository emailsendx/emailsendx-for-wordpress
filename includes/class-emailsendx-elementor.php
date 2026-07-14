<?php
/**
 * EmailSendX Sync — Elementor adapter.
 *
 * The second builder adapter, and the proof that the core was worth
 * building builder-agnostic: it maps Elementor controls onto the exact
 * same shortcodes WPBakery uses ({@see EmailSendX_Forms}), so a form
 * placed with Elementor renders byte-for-byte identically to one placed
 * with WPBakery or typed as a raw shortcode. No styling system is
 * duplicated — the controls just write the same shortcode attributes,
 * which resolve to the same CSS variables.
 *
 * Loading order matters here. The widget classes extend
 * `\Elementor\Widget_Base`, which does not exist until Elementor boots —
 * so declaring them at file-load time would fatal on any site without
 * Elementor. They therefore live in `includes/elementor/` and are only
 * required from inside the widget-registration hook, by which point
 * Elementor is guaranteed loaded. This class itself extends nothing and
 * is always safe to autoload.
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
 * Class EmailSendX_Elementor
 */
class EmailSendX_Elementor {

	/**
	 * Elementor category slug for our widgets.
	 */
	const CATEGORY = 'emailsendx';

	/**
	 * Wire the adapter.
	 *
	 * We deliberately hook Elementor's registration actions DIRECTLY rather
	 * than waiting on `elementor/loaded`. WordPress loads plugins in path
	 * order, and `elementor/` sorts before `emailsendx-for-wordpress/` — so
	 * by the time our bootstrap runs on `plugins_loaded`, Elementor has
	 * already fired `elementor/loaded`, and an add_action() for it would
	 * never execute (this cost us a silent no-registration bug once).
	 *
	 * The actions below fire later, during Elementor's editor/frontend init,
	 * so load order is irrelevant — and they only ever fire when Elementor
	 * is active, making this an inert no-op otherwise. ShaonPro.
	 */
	public function __construct() {
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_category' ) );

		// Modern (>= 3.5) and legacy hook names. register_widgets() is
		// idempotent, so it's harmless if both fire.
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
		add_action( 'elementor/widgets/widgets_registered', array( __CLASS__, 'register_widgets' ) );

		// Branded widget tiles in the editor panel.
		add_action( 'elementor/editor/after_enqueue_styles', array( __CLASS__, 'editor_icon_css' ) );
	}

	/**
	 * Add an "EmailSendX" panel category, seated directly under "Basic".
	 *
	 * Elementor's `add_category()` only ever APPENDS, `$categories` is a
	 * private property, and there's no filter on it — so a plain add_category()
	 * drops us dead last, below WooCommerce, where nobody scrolls. To seat the
	 * category properly we rebuild the ordered map with ours inserted after
	 * `basic` and write it back via reflection. If that's ever blocked, we fall
	 * back to a normal append rather than losing the category entirely.
	 *
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 * @return void
	 */
	public static function register_category( $manager ) {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'add_category' ) ) {
			return;
		}

		$properties = array(
			'title' => esc_html__( 'EmailSendX', 'emailsendx-sync' ),
			'icon'  => 'eicon-envelope',
		);

		$existing = method_exists( $manager, 'get_categories' ) ? $manager->get_categories() : array();

		// No "basic" to anchor to → just append.
		if ( ! is_array( $existing ) || ! isset( $existing['basic'] ) ) {
			$manager->add_category( self::CATEGORY, $properties );
			return;
		}

		$reordered = array();
		foreach ( $existing as $slug => $category ) {
			$reordered[ $slug ] = $category;
			if ( 'basic' === $slug ) {
				$reordered[ self::CATEGORY ] = $properties;
			}
		}

		try {
			$prop = new ReflectionProperty( $manager, 'categories' );
			$prop->setAccessible( true );
			$prop->setValue( $manager, $reordered );
		} catch ( \Throwable $e ) {
			$manager->add_category( self::CATEGORY, $properties ); // Safe fallback.
		}
	}

	/**
	 * Brand the two widget tiles in the Elementor panel. Elementor renders a
	 * widget's `get_icon()` value as `<i class="…">`, so a class backed by the
	 * EmailSendX mark gives us a proper branded tile instead of a generic
	 * grey eicon. ShaonPro.
	 *
	 * @return void
	 */
	public static function editor_icon_css() {
		$icon = EMAILSENDX_SYNC_URL . 'assets/img/icon-color.svg';

		// 1. The branded tile icon.
		$css = 'i.esx-eicon{display:inline-block;width:28px;height:28px;background-image:url('
			. esc_url( $icon )
			. ');background-size:contain;background-repeat:no-repeat;background-position:center;}';

		// 2. Make the tile LABELS fit. "EmailSendX Newsletter" is 21 chars and
		//    ran right to the edge of Elementor's narrow panel tile. Elementor
		//    renders each tile as
		//      <button class="elementor-element" data-library-element-type="…">
		//    so we can scope this to OUR two widgets only and leave every other
		//    widget in the panel completely untouched. The title is allowed to
		//    wrap, and we reserve two lines on both so the pair keeps an even
		//    height instead of one tile growing taller than the other.
		$sel = '.elementor-element[data-library-element-type="emailsendx_form"] .title,'
			. '.elementor-element[data-library-element-type="emailsendx_newsletter"] .title';

		$css .= $sel . '{white-space:normal;overflow-wrap:anywhere;text-align:center;'
			. 'line-height:1.3;padding:0 6px;min-height:2.6em;'
			. 'display:flex;align-items:center;justify-content:center;}';

		// Inline-only stylesheet (no src) — the WP-correct way to ship a few
		// bytes of CSS into the editor.
		wp_register_style( 'esx-eicon', false, array(), EMAILSENDX_SYNC_VERSION );
		wp_enqueue_style( 'esx-eicon' );
		wp_add_inline_style( 'esx-eicon', $css );
	}

	/**
	 * Load + register the widget classes. Safe to call from either the
	 * modern or legacy hook — Elementor is loaded by this point, so
	 * extending Widget_Base is now valid.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 * @return void
	 */
	public static function register_widgets( $manager ) {
		static $registered = false;

		// Guard against the modern + legacy hooks both firing.
		if ( $registered || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$registered = true;

		$dir = EMAILSENDX_SYNC_PATH . 'includes/elementor/';
		require_once $dir . 'class-emailsendx-elementor-base.php';
		require_once $dir . 'class-emailsendx-elementor-form.php';
		require_once $dir . 'class-emailsendx-elementor-newsletter.php';

		$widgets = array(
			new EmailSendX_Elementor_Form(),
			new EmailSendX_Elementor_Newsletter(),
		);

		foreach ( $widgets as $widget ) {
			if ( method_exists( $manager, 'register' ) ) {
				$manager->register( $widget );            // Elementor >= 3.5.
			} elseif ( method_exists( $manager, 'register_widget_type' ) ) {
				$manager->register_widget_type( $widget ); // Legacy.
			}
		}
	}
}
