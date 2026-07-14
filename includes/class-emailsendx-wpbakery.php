<?php
/**
 * EmailSendX Sync — WPBakery Page Builder adapter.
 *
 * A thin layer that exposes the builder-agnostic form shortcodes
 * ({@see EmailSendX_Forms}) as two drag-and-drop WPBakery elements:
 *
 *   • "EmailSendX Form"       → [emailsendx_form]
 *   • "EmailSendX Newsletter" → [emailsendx_newsletter]
 *
 * The element definitions carry no rendering logic of their own — they
 * just map builder controls onto shortcode attributes, so the front-end
 * output is byte-for-byte identical whether an element was placed via
 * WPBakery, typed as a raw shortcode, or (later) dropped via a Gutenberg
 * or Elementor adapter.
 *
 * The pickers are real `dropdown` selects, populated with the
 * workspace's forms + lists. The remote fetch is made only in an editor
 * context (backend editor or the frontend editor's admin-ajax param
 * load) and cached, so a normal front-end page view never triggers an
 * API call. When a workspace has no forms/lists yet — or isn't connected —
 * the dropdown says so and the field's help text links straight to the
 * place to fix it.
 *
 * The whole class self-guards on `WPB_VC_VERSION`: if WPBakery isn't
 * active, the constructor returns immediately and nothing is wired.
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
 * Class EmailSendX_WPBakery
 */
class EmailSendX_WPBakery {

	/**
	 * Element category label in the Add Element panel.
	 */
	const CATEGORY = 'EmailSendX';

	/**
	 * Wire the adapter — but only when WPBakery is present. ShaonPro.
	 */
	public function __construct() {
		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			return; // WPBakery not active — nothing to do.
		}

		add_action( 'vc_before_init', array( __CLASS__, 'register_elements' ) );

		// Seat our two elements at the start of the SECOND row of the Add
		// Element panel (see boost_lead_elements()).
		add_filter( 'vc_element_settings_filter', array( __CLASS__, 'boost_lead_elements' ), 10, 2 );

		// Branded element icon in the editor.
		add_action( 'admin_head', array( __CLASS__, 'editor_icon_css' ) );
	}

	/* ─── Placement ────────────────────────────────────────────────── */

	/**
	 * Seat the two EmailSendX elements at the start of the SECOND row of
	 * the Add Element panel — prominent, but after the core building
	 * blocks rather than leading the whole list.
	 *
	 * WPBakery sorts the panel by weight (descending), and every built-in
	 * element is weight 0, so the only lever for a mid-list position is to
	 * lift the elements that should precede ours. We nudge the standard
	 * lead elements — the ones that naturally fill the first row — above
	 * our 1010/1009, so ours begin the second row. Tuned for the usual
	 * ~8-per-row panel; on a much narrower panel they simply appear a row
	 * lower, never first.
	 *
	 * @param array  $settings Element settings being resolved.
	 * @param string $tag      Shortcode tag.
	 * @return array
	 */
	public static function boost_lead_elements( $settings, $tag ) {
		static $lead = array(
			'vc_row',
			'vc_column_text',
			'vc_icon',
			'vc_separator',
			'vc_zigzag',
			'vc_text_separator',
			'vc_message',
			'vc_hoverbox',
		);
		if ( is_array( $settings ) && in_array( $tag, $lead, true ) ) {
			$settings['weight'] = 1020; // Above our 1010/1009 → fills row 1.
		}
		return $settings;
	}

	/* ─── Element definitions ──────────────────────────────────────── */

	/**
	 * Register both elements with WPBakery. ShaonPro.
	 *
	 * @return void
	 */
	public static function register_elements() {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}

		$align_values = array(
			esc_html__( 'Default', 'emailsendx-sync' ) => '',
			esc_html__( 'Left', 'emailsendx-sync' )    => 'left',
			esc_html__( 'Center', 'emailsendx-sync' )  => 'center',
			esc_html__( 'Right', 'emailsendx-sync' )   => 'right',
		);

		// Build the picker <select> option maps + their contextual help.
		// The API is only queried in an editor context (see get_*_cached),
		// so a normal front-end page load never triggers a remote call.
		$forms_options = EmailSendX_Builder_Data::dropdown_options( 'forms' );
		$lists_options = EmailSendX_Builder_Data::dropdown_options( 'lists' );
		$forms_help    = EmailSendX_Builder_Data::picker_description( 'forms' );
		$lists_help    = EmailSendX_Builder_Data::picker_description( 'lists' );

		// Shared "Style" tab — identical controls on both elements. Each
		// maps to a CSS variable or modifier class on the shortcode wrapper
		// ({@see EmailSendX_Forms::inline_style()} / wrap_classes()).
		$style_group  = esc_html__( 'Style', 'emailsendx-sync' );
		$style_params = array(
			/* ── Layout ─────────────────────────────────────────────── */
			array(
				'type'       => 'dropdown',
				'heading'    => esc_html__( 'Alignment', 'emailsendx-sync' ),
				'param_name' => 'align',
				'value'      => $align_values,
				'std'        => '',
				'group'      => $style_group,
			),
			array(
				'type'        => 'dropdown',
				'heading'     => esc_html__( 'Width', 'emailsendx-sync' ),
				'param_name'  => 'width',
				'value'       => array(
					esc_html__( 'Default (480px)', 'emailsendx-sync' ) => '',
					esc_html__( 'Narrow (360px)', 'emailsendx-sync' )  => 'narrow',
					esc_html__( 'Wide (640px)', 'emailsendx-sync' )    => 'wide',
					esc_html__( 'Full width', 'emailsendx-sync' )      => 'full',
				),
				'std'         => '',
				'description' => esc_html__( 'Use Full width to fill the column it sits in.', 'emailsendx-sync' ),
				'group'       => $style_group,
			),
			array(
				'type'       => 'dropdown',
				'heading'    => esc_html__( 'Size', 'emailsendx-sync' ),
				'param_name' => 'size',
				'value'      => array(
					esc_html__( 'Default', 'emailsendx-sync' ) => '',
					esc_html__( 'Small', 'emailsendx-sync' )   => 'small',
					esc_html__( 'Large', 'emailsendx-sync' )   => 'large',
				),
				'std'        => '',
				'group'      => $style_group,
			),
			array(
				'type'       => 'dropdown',
				'heading'    => esc_html__( 'Field spacing', 'emailsendx-sync' ),
				'param_name' => 'spacing',
				'value'      => array(
					esc_html__( 'Default', 'emailsendx-sync' ) => '',
					esc_html__( 'Compact', 'emailsendx-sync' ) => 'compact',
					esc_html__( 'Relaxed', 'emailsendx-sync' ) => 'relaxed',
				),
				'std'        => '',
				'group'      => $style_group,
			),

			/* ── Fields ─────────────────────────────────────────────── */
			array(
				'type'        => 'dropdown',
				'heading'     => esc_html__( 'Field style', 'emailsendx-sync' ),
				'param_name'  => 'field_style',
				'value'       => array(
					esc_html__( 'Outlined', 'emailsendx-sync' )  => '',
					esc_html__( 'Filled', 'emailsendx-sync' )    => 'filled',
					esc_html__( 'Underline', 'emailsendx-sync' ) => 'underline',
				),
				'std'         => '',
				'description' => esc_html__( 'Outlined = bordered box. Filled = tinted, borderless. Underline = minimal bottom rule.', 'emailsendx-sync' ),
				'group'       => $style_group,
			),
			array(
				'type'       => 'dropdown',
				'heading'    => esc_html__( 'Corner radius', 'emailsendx-sync' ),
				'param_name' => 'radius',
				'value'      => array(
					esc_html__( 'Default', 'emailsendx-sync' ) => '',
					esc_html__( 'Rounded', 'emailsendx-sync' ) => 'rounded',
					esc_html__( 'Pill', 'emailsendx-sync' )    => 'pill',
					esc_html__( 'Square', 'emailsendx-sync' )  => 'square',
				),
				'std'        => '',
				'group'      => $style_group,
			),
			array(
				'type'        => 'dropdown',
				'heading'     => esc_html__( 'Labels', 'emailsendx-sync' ),
				'param_name'  => 'labels',
				'value'       => array(
					esc_html__( 'Show', 'emailsendx-sync' )              => '',
					esc_html__( 'Hidden (placeholder only)', 'emailsendx-sync' ) => 'hidden',
				),
				'std'         => '',
				'description' => esc_html__( 'Hidden keeps labels for screen readers.', 'emailsendx-sync' ),
				'group'       => $style_group,
			),
			array(
				'type'        => 'colorpicker',
				'heading'     => esc_html__( 'Field background', 'emailsendx-sync' ),
				'param_name'  => 'field_bg',
				'description' => esc_html__( 'Set this for forms on a dark or tinted section.', 'emailsendx-sync' ),
				'group'       => $style_group,
			),
			array(
				'type'       => 'colorpicker',
				'heading'    => esc_html__( 'Field text colour', 'emailsendx-sync' ),
				'param_name' => 'field_color',
				'group'      => $style_group,
			),
			array(
				'type'       => 'colorpicker',
				'heading'    => esc_html__( 'Field border colour', 'emailsendx-sync' ),
				'param_name' => 'border_color',
				'group'      => $style_group,
			),

			/* ── Button ─────────────────────────────────────────────── */
			array(
				'type'        => 'colorpicker',
				'heading'     => esc_html__( 'Accent colour', 'emailsendx-sync' ),
				'param_name'  => 'accent',
				'description' => esc_html__( 'Button background + input focus ring.', 'emailsendx-sync' ),
				'group'       => $style_group,
			),
			array(
				'type'       => 'colorpicker',
				'heading'    => esc_html__( 'Button text colour', 'emailsendx-sync' ),
				'param_name' => 'button_color',
				'group'      => $style_group,
			),
			array(
				'type'       => 'dropdown',
				'heading'    => esc_html__( 'Button style', 'emailsendx-sync' ),
				'param_name' => 'button_style',
				'value'      => array(
					esc_html__( 'Solid', 'emailsendx-sync' )   => '',
					esc_html__( 'Outline', 'emailsendx-sync' ) => 'outline',
				),
				'std'        => '',
				'group'      => $style_group,
			),
			array(
				'type'       => 'dropdown',
				'heading'    => esc_html__( 'Button alignment', 'emailsendx-sync' ),
				'param_name' => 'button_align',
				'value'      => array(
					esc_html__( 'Default', 'emailsendx-sync' ) => '',
					esc_html__( 'Left', 'emailsendx-sync' )    => 'left',
					esc_html__( 'Center', 'emailsendx-sync' )  => 'center',
					esc_html__( 'Right', 'emailsendx-sync' )   => 'right',
				),
				'std'        => '',
				'group'      => $style_group,
			),
			array(
				'type'       => 'checkbox',
				'heading'    => esc_html__( 'Full-width button', 'emailsendx-sync' ),
				'param_name' => 'button_full',
				'value'      => array( esc_html__( 'Yes', 'emailsendx-sync' ) => 'yes' ),
				'group'      => $style_group,
			),
			array(
				'type'        => 'colorpicker',
				'heading'     => esc_html__( 'Text colour', 'emailsendx-sync' ),
				'param_name'  => 'text_color',
				'description' => esc_html__( 'Labels + helper text.', 'emailsendx-sync' ),
				'group'       => $style_group,
			),
		);

		$css_param = array(
			'type'       => 'css_editor',
			'heading'    => esc_html__( 'Design Options', 'emailsendx-sync' ),
			'param_name' => 'css',
			'group'      => esc_html__( 'Design Options', 'emailsendx-sync' ),
		);

		/* ── EmailSendX Form ──────────────────────────────────────── */
		vc_map(
			array(
				'name'        => esc_html__( 'EmailSendX Form', 'emailsendx-sync' ),
				'base'        => 'emailsendx_form',
				'category'    => self::CATEGORY,
				'weight'      => 1010, // With boost_lead_elements(), seats this at the start of row 2.
				'icon'        => 'esx-vc-icon',
				'description' => esc_html__( 'Embed an opt-in form.', 'emailsendx-sync' ),
				'params'      => array_merge(
					array(
						array(
							'type'        => 'dropdown',
							'heading'     => esc_html__( 'Form', 'emailsendx-sync' ),
							'param_name'  => 'id',
							'value'       => $forms_options,
							'std'         => '',
							'admin_label' => true,
							'description' => $forms_help,
						),
					),
					$style_params,
					array( $css_param )
				),
			)
		);

		/* ── EmailSendX Newsletter ────────────────────────────────── */
		vc_map(
			array(
				'name'        => esc_html__( 'EmailSendX Newsletter', 'emailsendx-sync' ),
				'base'        => 'emailsendx_newsletter',
				'category'    => self::CATEGORY,
				'weight'      => 1009, // Directly beneath the Form element (row 2).
				'icon'        => 'esx-vc-icon',
				'description' => esc_html__( 'Quick subscribe box.', 'emailsendx-sync' ),
				'params'      => array_merge(
					array(
					array(
						'type'        => 'dropdown',
						'heading'     => esc_html__( 'Contact list', 'emailsendx-sync' ),
						'param_name'  => 'list',
						'value'       => $lists_options,
						'std'         => '',
						'admin_label' => true,
						'description' => $lists_help,
					),
					array(
						'type'       => 'textfield',
						'heading'    => esc_html__( 'Heading', 'emailsendx-sync' ),
						'param_name' => 'title',
						'value'      => '',
					),
					array(
						'type'       => 'textarea',
						'heading'    => esc_html__( 'Description', 'emailsendx-sync' ),
						'param_name' => 'description',
						'value'      => '',
					),
					array(
						'type'       => 'checkbox',
						'heading'    => esc_html__( 'Also collect first name', 'emailsendx-sync' ),
						'param_name' => 'name',
						'value'      => array( esc_html__( 'Yes', 'emailsendx-sync' ) => 'yes' ),
					),
					array(
						'type'       => 'textfield',
						'heading'    => esc_html__( 'Button text', 'emailsendx-sync' ),
						'param_name' => 'button',
						'value'      => esc_html__( 'Subscribe', 'emailsendx-sync' ),
					),
					array(
						'type'       => 'textfield',
						'heading'    => esc_html__( 'Email placeholder', 'emailsendx-sync' ),
						'param_name' => 'placeholder',
						'value'      => esc_html__( 'you@example.com', 'emailsendx-sync' ),
					),
					array(
						'type'       => 'textfield',
						'heading'    => esc_html__( 'Success message', 'emailsendx-sync' ),
						'param_name' => 'success',
						'value'      => esc_html__( 'Thanks! You are subscribed.', 'emailsendx-sync' ),
					),
					array(
						'type'       => 'textarea',
						'heading'    => esc_html__( 'Consent / fine print', 'emailsendx-sync' ),
						'param_name' => 'consent',
						'description' => esc_html__( 'Optional line shown under the form — e.g. how to unsubscribe.', 'emailsendx-sync' ),
						'value'      => '',
					),
					),
					$style_params,
					array( $css_param )
				),
			)
		);
	}

	/* ─── Editor chrome ────────────────────────────────────────────── */

	/**
	 * Brand the element tile icon in the WPBakery Add Element panel.
	 * Tiny inline rule scoped to the plugin's own icon class. ShaonPro.
	 *
	 * @return void
	 */
	public static function editor_icon_css() {
		$icon = EMAILSENDX_SYNC_URL . 'assets/img/icon-color.svg';
		// The element-icon box is 32×32; size the mark to nearly fill it so
		// it reads at the same scale as the built-in element icons.
		printf(
			'<style id="esx-vc-icon-css">.vc_element-icon.esx-vc-icon,.esx-vc-icon{background-image:url(%s)!important;background-size:30px 30px!important;background-position:center!important;background-repeat:no-repeat!important;}</style>',
			esc_url( $icon )
		);
	}
}
