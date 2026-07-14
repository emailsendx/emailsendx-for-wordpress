<?php
/**
 * EmailSendX Sync — Elementor "EmailSendX Newsletter" widget.
 *
 * Maps onto [emailsendx_newsletter]. Only required from inside Elementor's
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
 * Class EmailSendX_Elementor_Newsletter
 */
class EmailSendX_Elementor_Newsletter extends EmailSendX_Elementor_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'emailsendx_newsletter';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'EmailSendX Newsletter', 'emailsendx-sync' );
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
		return array( 'emailsendx', 'newsletter', 'subscribe', 'signup', 'email', 'list' );
	}

	/**
	 * Content tab = list picker + copy. Visuals live in the shared Style
	 * controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'esx_content',
			array(
				'label' => esc_html__( 'Newsletter', 'emailsendx-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'list_id',
			array(
				'label'       => esc_html__( 'Contact list', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => EmailSendX_Builder_Data::select_options( 'lists' ),
				'description' => EmailSendX_Builder_Data::picker_description( 'lists' ),
				'label_block' => true,
			)
		);
		$this->add_control(
			'title',
			array(
				'label'   => esc_html__( 'Heading', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);
		$this->add_control(
			'description',
			array(
				'label'   => esc_html__( 'Description', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => '',
			)
		);
		$this->add_control(
			'name',
			array(
				'label'        => esc_html__( 'Also collect first name', 'emailsendx-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);
		$this->add_control(
			'button',
			array(
				'label'   => esc_html__( 'Button text', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'Subscribe', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'placeholder',
			array(
				'label'   => esc_html__( 'Email placeholder', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'you@example.com', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'success',
			array(
				'label'   => esc_html__( 'Success message', 'emailsendx-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'Thanks! You are subscribed.', 'emailsendx-sync' ),
			)
		);
		$this->add_control(
			'consent',
			array(
				'label'       => esc_html__( 'Consent / fine print', 'emailsendx-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => '',
				'description' => esc_html__( 'Optional line shown under the form — e.g. how to unsubscribe.', 'emailsendx-sync' ),
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

		$list_id = isset( $settings['list_id'] ) ? (string) $settings['list_id'] : '';
		if ( '' === $list_id ) {
			if ( current_user_can( 'edit_posts' ) ) {
				echo '<p style="padding:10px 14px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">'
					. esc_html__( 'EmailSendX Newsletter: choose a contact list in the Content tab.', 'emailsendx-sync' )
					. '</p>';
			}
			return;
		}

		$atts         = $this->style_atts( $settings );
		$atts['list'] = $list_id;

		foreach ( array( 'title', 'description', 'name', 'button', 'placeholder', 'success', 'consent' ) as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
				$atts[ $key ] = (string) $settings[ $key ];
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the
		// shortcode renders escaped markup; attributes are sanitised in
		// render_shortcode().
		echo $this->render_shortcode( 'emailsendx_newsletter', $atts );
	}
}
