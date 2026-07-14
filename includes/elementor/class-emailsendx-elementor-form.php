<?php
/**
 * EmailSendX Sync — Elementor "EmailSendX Form" widget.
 *
 * Maps onto [emailsendx_form]. Only required from inside Elementor's
 * widget-registration hook — see the note in the base class.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Elementor_Form
 */
class EmailSendX_Elementor_Form extends EmailSendX_Elementor_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'emailsendx_form';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'EmailSendX Form', 'emailsendx-sync' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'esx-eicon'; // Branded tile — see EmailSendX_Elementor::editor_icon_css().
	}

	/**
	 * @return array
	 */
	public function get_keywords() {
		return array( 'emailsendx', 'form', 'signup', 'opt-in', 'subscribe', 'email' );
	}

	/**
	 * Content tab = the form picker. Everything visual lives in the shared
	 * Style controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'esx_content',
			array(
				'label' => esc_html__( 'Form', 'emailsendx-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'form_id',
			array(
				'label'       => esc_html__( 'Form', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => EmailSendX_Builder_Data::select_options( 'forms' ),
				'description' => EmailSendX_Builder_Data::picker_description( 'forms' ),
				'label_block' => true,
			)
		);
		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Render via the shared shortcode — identical output to WPBakery.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$form_id = isset( $settings['form_id'] ) ? (string) $settings['form_id'] : '';
		if ( '' === $form_id ) {
			// Editors get a hint; visitors see nothing.
			if ( current_user_can( 'edit_posts' ) ) {
				echo '<p style="padding:10px 14px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">'
					. esc_html__( 'EmailSendX Form: choose a form in the Content tab.', 'emailsendx-sync' )
					. '</p>';
			}
			return;
		}

		$atts       = $this->style_atts( $settings );
		$atts['id'] = $form_id;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the
		// shortcode renders escaped markup; attributes are sanitised in
		// render_shortcode().
		echo $this->render_shortcode( 'emailsendx_form', $atts );
	}
}
