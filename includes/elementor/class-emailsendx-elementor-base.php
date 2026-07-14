<?php
/**
 * EmailSendX Sync — shared Elementor widget base.
 *
 * Holds the Style controls both widgets share (layout, fields, button) and
 * the mapping from Elementor settings back to shortcode attributes. The
 * concrete widgets only declare their own identity + content control (the
 * form / list picker) and hand off to `render_shortcode()`.
 *
 * IMPORTANT: this file extends `\Elementor\Widget_Base` and must therefore
 * only ever be `require`d from inside Elementor's widget-registration hook
 * — see {@see EmailSendX_Elementor::register_widgets()}. Autoloading it
 * would fatal on sites without Elementor.
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
 * Class EmailSendX_Elementor_Base
 */
abstract class EmailSendX_Elementor_Base extends \Elementor\Widget_Base {

	/**
	 * Both widgets live in the EmailSendX panel category.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( EmailSendX_Elementor::CATEGORY );
	}

	/**
	 * Let Elementor enqueue the form assets ONLY on pages that actually use
	 * a widget. The handles are registered by
	 * {@see EmailSendX_Forms::register_assets()} on `wp_enqueue_scripts`.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( 'emailsendx-forms' );
	}

	/**
	 * @return array
	 */
	public function get_script_depends() {
		return array( 'emailsendx-forms' );
	}

	/* ─── Shared Style controls ────────────────────────────────────── */

	/**
	 * Register the Layout / Fields / Button style sections. Every control
	 * maps 1:1 onto a shortcode attribute, which in turn resolves to a CSS
	 * variable or modifier class — the same system WPBakery drives, so both
	 * builders produce identical markup.
	 *
	 * @return void
	 */
	protected function register_style_controls() {
		/* ── Layout ─────────────────────────────────────────────────── */
		$this->start_controls_section(
			'esx_layout',
			array(
				'label' => esc_html__( 'Layout', 'emailsendx-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'align',
			array(
				'label'   => esc_html__( 'Alignment', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => esc_html__( 'Default', 'emailsendx-sync' ),
					'left'   => esc_html__( 'Left', 'emailsendx-sync' ),
					'center' => esc_html__( 'Center', 'emailsendx-sync' ),
					'right'  => esc_html__( 'Right', 'emailsendx-sync' ),
				),
			)
		);
		$this->add_control(
			'width',
			array(
				'label'       => esc_html__( 'Width', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''       => esc_html__( 'Default (480px)', 'emailsendx-sync' ),
					'narrow' => esc_html__( 'Narrow (360px)', 'emailsendx-sync' ),
					'wide'   => esc_html__( 'Wide (640px)', 'emailsendx-sync' ),
					'full'   => esc_html__( 'Full width', 'emailsendx-sync' ),
				),
				'description' => esc_html__( 'Use Full width to fill the column it sits in.', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'size',
			array(
				'label'   => esc_html__( 'Size', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''      => esc_html__( 'Default', 'emailsendx-sync' ),
					'small' => esc_html__( 'Small', 'emailsendx-sync' ),
					'large' => esc_html__( 'Large', 'emailsendx-sync' ),
				),
			)
		);
		$this->add_control(
			'spacing',
			array(
				'label'   => esc_html__( 'Field spacing', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''        => esc_html__( 'Default', 'emailsendx-sync' ),
					'compact' => esc_html__( 'Compact', 'emailsendx-sync' ),
					'relaxed' => esc_html__( 'Relaxed', 'emailsendx-sync' ),
				),
			)
		);
		$this->end_controls_section();

		/* ── Fields ─────────────────────────────────────────────────── */
		$this->start_controls_section(
			'esx_fields',
			array(
				'label' => esc_html__( 'Fields', 'emailsendx-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'field_style',
			array(
				'label'       => esc_html__( 'Field style', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''          => esc_html__( 'Outlined', 'emailsendx-sync' ),
					'filled'    => esc_html__( 'Filled', 'emailsendx-sync' ),
					'underline' => esc_html__( 'Underline', 'emailsendx-sync' ),
				),
				'description' => esc_html__( 'Outlined = bordered box. Filled = tinted, borderless. Underline = minimal bottom rule.', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'radius',
			array(
				'label'   => esc_html__( 'Corner radius', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''        => esc_html__( 'Default', 'emailsendx-sync' ),
					'rounded' => esc_html__( 'Rounded', 'emailsendx-sync' ),
					'pill'    => esc_html__( 'Pill', 'emailsendx-sync' ),
					'square'  => esc_html__( 'Square', 'emailsendx-sync' ),
				),
			)
		);
		$this->add_control(
			'labels',
			array(
				'label'       => esc_html__( 'Labels', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''       => esc_html__( 'Show', 'emailsendx-sync' ),
					'hidden' => esc_html__( 'Hidden (placeholder only)', 'emailsendx-sync' ),
				),
				'description' => esc_html__( 'Hidden keeps labels for screen readers.', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'field_bg',
			array(
				'label'       => esc_html__( 'Field background', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => esc_html__( 'Set this for forms on a dark or tinted section.', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'field_color',
			array(
				'label' => esc_html__( 'Field text colour', 'emailsendx-sync' ),
				'type'  => \Elementor\Controls_Manager::COLOR,
			)
		);
		$this->add_control(
			'border_color',
			array(
				'label' => esc_html__( 'Field border colour', 'emailsendx-sync' ),
				'type'  => \Elementor\Controls_Manager::COLOR,
			)
		);
		$this->end_controls_section();

		/* ── Button ─────────────────────────────────────────────────── */
		$this->start_controls_section(
			'esx_button',
			array(
				'label' => esc_html__( 'Button & text', 'emailsendx-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'accent',
			array(
				'label'       => esc_html__( 'Accent colour', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => esc_html__( 'Button background + input focus ring.', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'button_color',
			array(
				'label' => esc_html__( 'Button text colour', 'emailsendx-sync' ),
				'type'  => \Elementor\Controls_Manager::COLOR,
			)
		);
		$this->add_control(
			'button_style',
			array(
				'label'   => esc_html__( 'Button style', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''        => esc_html__( 'Solid', 'emailsendx-sync' ),
					'outline' => esc_html__( 'Outline', 'emailsendx-sync' ),
				),
			)
		);
		$this->add_control(
			'button_align',
			array(
				'label'   => esc_html__( 'Button alignment', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => esc_html__( 'Default', 'emailsendx-sync' ),
					'left'   => esc_html__( 'Left', 'emailsendx-sync' ),
					'center' => esc_html__( 'Center', 'emailsendx-sync' ),
					'right'  => esc_html__( 'Right', 'emailsendx-sync' ),
				),
			)
		);
		$this->add_control(
			'button_full',
			array(
				'label'        => esc_html__( 'Full-width button', 'emailsendx-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);
		$this->add_control(
			'text_color',
			array(
				'label'       => esc_html__( 'Text colour', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => esc_html__( 'Labels + helper text.', 'emailsendx-sync' ),
			)
		);
		$this->end_controls_section();
	}

	/* ─── Settings → shortcode ─────────────────────────────────────── */

	/**
	 * Collect the shared style settings as shortcode attributes.
	 *
	 * @param array $settings Elementor settings.
	 * @return array<string,string>
	 */
	protected function style_atts( $settings ) {
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

		$atts = array();
		foreach ( $keys as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
				$atts[ $key ] = (string) $settings[ $key ];
			}
		}
		return $atts;
	}

	/**
	 * Build + run a shortcode from an attribute map. Keys are reduced to a
	 * safe charset and values have quotes/brackets stripped, so no control
	 * value can break out of the shortcode it's interpolated into.
	 *
	 * @param string $tag  Shortcode tag.
	 * @param array  $atts Attributes.
	 * @return string Rendered HTML.
	 */
	protected function render_shortcode( $tag, $atts ) {
		$out = '[' . $tag;
		foreach ( $atts as $key => $value ) {
			$key   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );
			$value = str_replace( array( '"', '[', ']' ), '', (string) $value );
			if ( '' === $key || '' === $value ) {
				continue;
			}
			$out .= ' ' . $key . '="' . $value . '"';
		}
		$out .= ']';

		return do_shortcode( $out );
	}
}
