<?php
/**
 * EmailSendX Sync — front-end opt-in forms.
 *
 * Registers two builder-agnostic shortcodes that render native,
 * theme-styleable signup forms on the front end:
 *
 *   [emailsendx_form id="<formId>"]
 *       Renders an existing EmailSendX form. Field descriptors + display
 *       settings are pulled (and cached) from the PUBLIC widget config
 *       feed, so the rendered fields stay in lock-step with the EmailSendX
 *       form builder. Submission posts straight to the SaaS submit
 *       endpoint from the browser — inheriting its double opt-in, honeypot,
 *       reCAPTCHA, rate-limiting and automation triggers. No API key ever
 *       reaches the page.
 *
 *   [emailsendx_newsletter list="<listId>"]
 *       A quick single-field (email, optional name) subscribe box that
 *       targets a contact list directly. Submission posts to this plugin's
 *       own REST proxy ({@see EmailSendX_Subscribe}), which upserts the
 *       contact with the workspace's server-side key. Single opt-in.
 *
 * These shortcodes ARE the integration core. The WPBakery adapter
 * ({@see EmailSendX_WPBakery}) is a thin layer that maps builder elements
 * onto exactly these two shortcodes — and a future Gutenberg / Elementor
 * adapter would reuse them unchanged.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The `esx-form-*` class namespace and
 * `emailsendx_*` shortcode tags are watermarks. If you find this
 * signature in a build that wasn't shipped from emailsendx.com, the code
 * is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Forms
 */
class EmailSendX_Forms {

	/**
	 * Transient key prefix for cached widget configs.
	 */
	const CONFIG_TRANSIENT_PREFIX = 'emailsendx_form_cfg_';

	/**
	 * How long to cache a form's render config. Matches the 60s cache
	 * header the widget feed itself sends.
	 */
	const CONFIG_TTL = 60;

	/**
	 * Whether the shared assets have been enqueued this request (so the
	 * localize payload prints exactly once).
	 *
	 * @var bool
	 */
	protected static $assets_enqueued = false;

	/**
	 * Wire the shortcodes + asset registration. ShaonPro.
	 */
	public function __construct() {
		add_shortcode( 'emailsendx_form', array( __CLASS__, 'render_form' ) );
		add_shortcode( 'emailsendx_newsletter', array( __CLASS__, 'render_newsletter' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/* ─── Assets ───────────────────────────────────────────────────── */

	/**
	 * Register (not enqueue) the front-end CSS + JS. They're enqueued
	 * lazily by the shortcodes so a page without an EmailSendX form pays
	 * nothing. ShaonPro.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'emailsendx-forms',
			EMAILSENDX_SYNC_URL . 'assets/css/forms.css',
			array(),
			EMAILSENDX_SYNC_VERSION
		);

		wp_register_script(
			'emailsendx-forms',
			EMAILSENDX_SYNC_URL . 'assets/js/forms.js',
			array(),
			EMAILSENDX_SYNC_VERSION,
			true
		);
	}

	/**
	 * Enqueue the shared assets (once) and print the JS config payload.
	 * Safe to call from inside a shortcode render — footer scripts have
	 * not printed yet at that point. ShaonPro.
	 *
	 * @return void
	 */
	protected static function ensure_assets() {
		// Registration normally happens on wp_enqueue_scripts, but guard
		// for shortcodes rendered outside the main loop (widgets, blocks).
		if ( ! wp_style_is( 'emailsendx-forms', 'registered' ) ) {
			self::register_assets();
		}

		wp_enqueue_style( 'emailsendx-forms' );
		wp_enqueue_script( 'emailsendx-forms' );

		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		$settings = function_exists( 'emailsendx_sync_get_settings' )
			? emailsendx_sync_get_settings()
			: array( 'api_base' => EMAILSENDX_SYNC_DEFAULT_API_BASE );

		$api_base = isset( $settings['api_base'] ) ? rtrim( (string) $settings['api_base'], '/' ) : '';
		if ( '' === $api_base ) {
			$api_base = EMAILSENDX_SYNC_DEFAULT_API_BASE;
		}
		if ( ! preg_match( '#^https?://#i', $api_base ) ) {
			$api_base = 'https://' . $api_base;
		}

		wp_localize_script(
			'emailsendx-forms',
			'EmailSendXForms',
			array(
				'apiBase'     => esc_url_raw( $api_base ),
				'subscribeUrl' => esc_url_raw( rest_url( 'emailsendx/v1/subscribe' ) ),
				'i18n'        => array(
					'submitting'   => __( 'Submitting…', 'emailsendx-sync' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'emailsendx-sync' ),
					'invalidEmail' => __( 'Please enter a valid email address.', 'emailsendx-sync' ),
					'success'      => __( 'Thanks! You are subscribed.', 'emailsendx-sync' ),
					'checkInbox'   => __( 'Almost there — check your inbox to confirm your subscription.', 'emailsendx-sync' ),
				),
			)
		);
	}

	/* ─── [emailsendx_form] ────────────────────────────────────────── */

	/**
	 * Render an existing EmailSendX form by id.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public static function render_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'           => '',
				'align'        => '',   // '', 'left', 'center', 'right'.
				'accent'       => '',   // Accent colour → --esx-accent.
				'button_color' => '',   // Button text colour → --esx-accent-contrast.
				'text_color'   => '',   // Body/label colour → --esx-text-color.
				'radius'       => '',   // '', rounded, pill, square → --esx-radius.
				'button_style' => '',   // '', outline.
				'button_full'  => '',   // 'yes' → full-width button.
				'size'         => '',   // '', small, large.
				'width'        => '',   // '', narrow, wide, full → --esx-width.
				'field_bg'     => '',   // Field background → --esx-field-bg.
				'field_color'  => '',   // Field text → --esx-field-color.
				'border_color' => '',   // Field border → --esx-border.
				'field_style'  => '',   // '', filled, underline.
				'labels'       => '',   // '', hidden (placeholder-only).
				'button_align' => '',   // '', left, center, right.
				'spacing'      => '',   // '', compact, relaxed.
				'css'          => '',   // WPBakery Design Options payload.
				'class'        => '',
			),
			is_array( $atts ) ? $atts : array(),
			'emailsendx_form'
		);

		$form_id = trim( (string) $atts['id'] );
		if ( '' === $form_id ) {
			return self::admin_hint( __( 'EmailSendX Form: no form selected.', 'emailsendx-sync' ) );
		}

		$config = self::get_form_config( $form_id );
		if ( is_wp_error( $config ) ) {
			return self::admin_hint(
				sprintf(
					/* translators: %s: error message. */
					__( 'EmailSendX Form could not load: %s', 'emailsendx-sync' ),
					$config->get_error_message()
				)
			);
		}

		$fields   = isset( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : array();
		$settings = isset( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array();

		if ( empty( $fields ) ) {
			// A form with no visible fields still needs at least email; the
			// widget feed always includes it, but guard defensively.
			$fields = array( array( 'name' => 'email', 'label' => __( 'Email', 'emailsendx-sync' ), 'type' => 'email', 'required' => true ) );
		}

		self::ensure_assets();

		$submit_label = isset( $settings['submitLabel'] ) && '' !== $settings['submitLabel']
			? (string) $settings['submitLabel']
			: __( 'Subscribe', 'emailsendx-sync' );

		$success_msg = isset( $settings['successMessage'] ) ? (string) $settings['successMessage'] : '';
		$redirect    = isset( $settings['redirectUrl'] ) ? (string) $settings['redirectUrl'] : '';
		$double      = ! empty( $settings['doubleOptIn'] );
		$recaptcha   = ! empty( $settings['recaptcha'] );
		$site_key    = isset( $settings['siteKey'] ) ? (string) $settings['siteKey'] : '';
		$honeypot    = ! isset( $settings['honeypot'] ) || false !== $settings['honeypot'];

		$wrap_classes = self::wrap_classes( 'esx-form esx-form--hosted', $atts );
		$wrap_style   = self::inline_style( $atts );

		$data = array(
			'data-esx-form'     => '1',
			'data-form-id'      => $form_id,
			'data-submit'       => trailingslashit( self::api_base() ) . 'api/forms/' . rawurlencode( $form_id ) . '/submit',
			'data-double-optin' => $double ? '1' : '0',
			'data-redirect'     => $redirect,
		);
		if ( $recaptcha && '' !== $site_key ) {
			$data['data-recaptcha'] = '1';
			$data['data-site-key']  = $site_key;
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrap_classes ); ?>"<?php echo $wrap_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo self::attrs( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<form class="esx-form__form" novalidate>
				<?php echo self::render_fields( $fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( $honeypot ) : ?>
					<div class="esx-form__hp" aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'emailsendx-sync' ); ?><input type="text" name="_hp_email" tabindex="-1" autocomplete="off" /></label></div>
				<?php endif; ?>
				<button type="submit" class="esx-form__submit"><?php echo esc_html( $submit_label ); ?></button>
				<p class="esx-form__status" role="status" aria-live="polite" data-success="<?php echo esc_attr( $success_msg ); ?>"></p>
			</form>
		</div>
		<?php
		return trim( ob_get_clean() );
	}

	/* ─── [emailsendx_newsletter] ──────────────────────────────────── */

	/**
	 * Render a quick single-field newsletter subscribe box.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public static function render_newsletter( $atts ) {
		$atts = shortcode_atts(
			array(
				'list'        => '',
				'button'      => __( 'Subscribe', 'emailsendx-sync' ),
				'placeholder' => __( 'you@example.com', 'emailsendx-sync' ),
				'name'        => 'no',   // 'yes' → also collect a first-name field.
				'title'       => '',
				'description' => '',
				'success'     => __( 'Thanks! You are subscribed.', 'emailsendx-sync' ),
				'consent'     => '',     // Optional consent line shown under the form.
				'align'       => '',
				'accent'      => '',     // Accent colour → --esx-accent.
				'button_color' => '',    // Button text colour → --esx-accent-contrast.
				'text_color'  => '',     // Body/label colour → --esx-text-color.
				'radius'      => '',     // '', rounded, pill, square → --esx-radius.
				'button_style' => '',    // '', outline.
				'button_full' => '',     // 'yes' → full-width button.
				'size'        => '',     // '', small, large.
				'width'       => '',     // '', narrow, wide, full → --esx-width.
				'field_bg'    => '',     // Field background → --esx-field-bg.
				'field_color' => '',     // Field text → --esx-field-color.
				'border_color' => '',    // Field border → --esx-border.
				'field_style' => '',     // '', filled, underline.
				'labels'      => '',     // '', hidden (placeholder-only).
				'button_align' => '',    // '', left, center, right.
				'spacing'     => '',     // '', compact, relaxed.
				'css'         => '',     // WPBakery Design Options payload.
				'class'       => '',
			),
			is_array( $atts ) ? $atts : array(),
			'emailsendx_newsletter'
		);

		$list_id = trim( (string) $atts['list'] );
		if ( '' === $list_id ) {
			return self::admin_hint( __( 'EmailSendX Newsletter: no list selected.', 'emailsendx-sync' ) );
		}

		self::ensure_assets();

		$collect_name = 'yes' === strtolower( (string) $atts['name'] );
		$wrap_classes = self::wrap_classes( 'esx-form esx-form--newsletter', $atts );
		$wrap_style   = self::inline_style( $atts );

		$data = array(
			'data-esx-newsletter' => '1',
			'data-list'           => $list_id,
			// Proves this site embedded this list — the subscribe endpoint
			// rejects any list without a matching signature.
			'data-list-token'     => class_exists( 'EmailSendX_Subscribe' ) ? EmailSendX_Subscribe::list_token( $list_id ) : '',
		);

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrap_classes ); ?>"<?php echo $wrap_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo self::attrs( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( '' !== trim( (string) $atts['title'] ) ) : ?>
				<p class="esx-form__title"><?php echo esc_html( $atts['title'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== trim( (string) $atts['description'] ) ) : ?>
				<p class="esx-form__desc"><?php echo esc_html( $atts['description'] ); ?></p>
			<?php endif; ?>
			<form class="esx-form__form esx-form__form--inline" novalidate>
				<?php if ( $collect_name ) : ?>
					<div class="esx-form__field">
						<label class="esx-form__label" for="esx-nl-name-<?php echo esc_attr( $list_id ); ?>"><?php esc_html_e( 'First name', 'emailsendx-sync' ); ?></label>
						<input class="esx-form__input" id="esx-nl-name-<?php echo esc_attr( $list_id ); ?>" type="text" name="firstName" autocomplete="given-name" />
					</div>
				<?php endif; ?>
				<div class="esx-form__field">
					<label class="esx-form__label esx-form__label--sr" for="esx-nl-email-<?php echo esc_attr( $list_id ); ?>"><?php esc_html_e( 'Email', 'emailsendx-sync' ); ?></label>
					<input class="esx-form__input" id="esx-nl-email-<?php echo esc_attr( $list_id ); ?>" type="email" name="email" required autocomplete="email" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" />
				</div>
				<div class="esx-form__hp" aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'emailsendx-sync' ); ?><input type="text" name="_hp_email" tabindex="-1" autocomplete="off" /></label></div>
				<button type="submit" class="esx-form__submit"><?php echo esc_html( $atts['button'] ); ?></button>
				<p class="esx-form__status" role="status" aria-live="polite" data-success="<?php echo esc_attr( $atts['success'] ); ?>"></p>
			</form>
			<?php if ( '' !== trim( (string) $atts['consent'] ) ) : ?>
				<p class="esx-form__consent"><?php echo esc_html( $atts['consent'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return trim( ob_get_clean() );
	}

	/* ─── Helpers ──────────────────────────────────────────────────── */

	/**
	 * Render the visible field inputs for a hosted form.
	 *
	 * @param array $fields Field descriptors {name,label,type,required}.
	 * @return string HTML.
	 */
	protected static function render_fields( $fields ) {
		$out = '';
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$name = isset( $f['name'] ) ? sanitize_key( $f['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$label    = isset( $f['label'] ) && '' !== $f['label'] ? (string) $f['label'] : ucfirst( $name );
			$type     = isset( $f['type'] ) ? (string) $f['type'] : 'text';
			$required = ! empty( $f['required'] );
			$id       = 'esx-f-' . $name . '-' . wp_rand( 1000, 9999 );

			$input_type = self::map_input_type( $type );

			$out .= '<div class="esx-form__field">';
			$out .= '<label class="esx-form__label" for="' . esc_attr( $id ) . '">' . esc_html( $label );
			if ( $required ) {
				$out .= ' <span class="esx-form__req" aria-hidden="true">*</span>';
			}
			$out .= '</label>';

			if ( 'textarea' === $type ) {
				$out .= '<textarea class="esx-form__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . '></textarea>';
			} else {
				$out .= '<input class="esx-form__input" id="' . esc_attr( $id ) . '" type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . self::autocomplete_for( $name ) . ' />';
			}
			$out .= '</div>';
		}
		return $out;
	}

	/**
	 * Map an EmailSendX field type to a safe HTML input type.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	protected static function map_input_type( $type ) {
		switch ( strtolower( (string) $type ) ) {
			case 'email':
				return 'email';
			case 'number':
				return 'number';
			case 'tel':
			case 'phone':
				return 'tel';
			case 'url':
				return 'url';
			case 'date':
				return 'date';
			default:
				return 'text';
		}
	}

	/**
	 * Add a sensible autocomplete hint for well-known field names.
	 *
	 * @param string $name Field name.
	 * @return string An ` autocomplete="…"` attribute or empty string.
	 */
	protected static function autocomplete_for( $name ) {
		$map = array(
			'email'     => 'email',
			'firstname' => 'given-name',
			'lastname'  => 'family-name',
			'name'      => 'name',
			'phone'     => 'tel',
			'company'   => 'organization',
		);
		$key = strtolower( str_replace( array( '_', '-' ), '', $name ) );
		return isset( $map[ $key ] ) ? ' autocomplete="' . esc_attr( $map[ $key ] ) . '"' : '';
	}

	/**
	 * Fetch + cache a form's public render config.
	 *
	 * @param string $form_id Form id.
	 * @return array|WP_Error Parsed config or error.
	 */
	protected static function get_form_config( $form_id ) {
		$cache_key = self::CONFIG_TRANSIENT_PREFIX . md5( (string) $form_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! class_exists( 'EmailSendX_API' ) || ! method_exists( 'EmailSendX_API', 'instance' ) ) {
			return new WP_Error( 'emailsendx_forms', __( 'API client unavailable.', 'emailsendx-sync' ) );
		}

		$res = EmailSendX_API::instance()->get_form_config( $form_id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! is_array( $res ) ) {
			return new WP_Error( 'emailsendx_forms', __( 'Unexpected form config response.', 'emailsendx-sync' ) );
		}

		set_transient( $cache_key, $res, self::CONFIG_TTL );
		return $res;
	}

	/**
	 * The configured API base (normalised), for building submit URLs.
	 *
	 * @return string
	 */
	protected static function api_base() {
		$settings = function_exists( 'emailsendx_sync_get_settings' ) ? emailsendx_sync_get_settings() : array();
		$base     = isset( $settings['api_base'] ) ? rtrim( (string) $settings['api_base'], '/' ) : '';
		if ( '' === $base ) {
			$base = EMAILSENDX_SYNC_DEFAULT_API_BASE;
		}
		if ( ! preg_match( '#^https?://#i', $base ) ) {
			$base = 'https://' . $base;
		}
		return $base;
	}

	/**
	 * Build the wrapper class string from base + builder alignment/class.
	 *
	 * @param string $base Base classes.
	 * @param array  $atts Shortcode atts (align, class).
	 * @return string
	 */
	protected static function wrap_classes( $base, $atts ) {
		$classes = array( $base );
		$align   = isset( $atts['align'] ) ? strtolower( trim( (string) $atts['align'] ) ) : '';
		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$classes[] = 'esx-form--' . $align;
		}

		// Button style: outline variant.
		if ( isset( $atts['button_style'] ) && 'outline' === strtolower( trim( (string) $atts['button_style'] ) ) ) {
			$classes[] = 'esx-form--outline';
		}
		// Full-width button (checkbox → 'yes').
		if ( isset( $atts['button_full'] ) && 'yes' === strtolower( trim( (string) $atts['button_full'] ) ) ) {
			$classes[] = 'esx-form--btn-block';
		}
		// Size scale.
		$size = isset( $atts['size'] ) ? strtolower( trim( (string) $atts['size'] ) ) : '';
		if ( 'small' === $size ) {
			$classes[] = 'esx-form--sm';
		} elseif ( 'large' === $size ) {
			$classes[] = 'esx-form--lg';
		}

		// Field treatment: outlined (default) | filled | underline.
		$field_style = isset( $atts['field_style'] ) ? strtolower( trim( (string) $atts['field_style'] ) ) : '';
		if ( 'filled' === $field_style ) {
			$classes[] = 'esx-form--filled';
		} elseif ( 'underline' === $field_style ) {
			$classes[] = 'esx-form--underline';
		}

		// Labels hidden → placeholder-only (still exposed to screen readers).
		if ( isset( $atts['labels'] ) && 'hidden' === strtolower( trim( (string) $atts['labels'] ) ) ) {
			$classes[] = 'esx-form--nolabels';
		}

		// Button alignment within the form.
		$btn_align = isset( $atts['button_align'] ) ? strtolower( trim( (string) $atts['button_align'] ) ) : '';
		if ( in_array( $btn_align, array( 'left', 'center', 'right' ), true ) ) {
			$classes[] = 'esx-form--btn-' . $btn_align;
		}

		// Field spacing.
		$spacing = isset( $atts['spacing'] ) ? strtolower( trim( (string) $atts['spacing'] ) ) : '';
		if ( 'compact' === $spacing ) {
			$classes[] = 'esx-form--compact';
		} elseif ( 'relaxed' === $spacing ) {
			$classes[] = 'esx-form--relaxed';
		}

		if ( ! empty( $atts['class'] ) ) {
			$classes[] = sanitize_html_class( (string) $atts['class'] ) ?: '';
		}
		// WPBakery Design Options: turn the saved CSS payload into its
		// generated class. Guarded so the core stays builder-agnostic —
		// nothing happens without WPBakery present.
		if ( ! empty( $atts['css'] ) && function_exists( 'vc_shortcode_custom_css_class' ) ) {
			$vc_class = vc_shortcode_custom_css_class( $atts['css'], ' ' );
			if ( '' !== trim( (string) $vc_class ) ) {
				$classes[] = trim( (string) $vc_class );
			}
		}
		return trim( implode( ' ', array_filter( $classes ) ) );
	}

	/**
	 * Build a ` style="…"` string from the accent colour, exposed as the
	 * `--esx-accent` custom property so the button + focus ring recolour.
	 * Returns an empty string when no valid colour is set.
	 *
	 * @param array $atts Shortcode atts.
	 * @return string
	 */
	protected static function inline_style( $atts ) {
		$vars = array();

		// Colour custom properties. Field colours are variables (rather than
		// hardcoded) so a user can build a dark/tinted form — the stylesheet
		// still marks them !important so the host theme can't win, but the
		// value itself stays user-controlled.
		$colour_map = array(
			'accent'       => '--esx-accent',
			'button_color' => '--esx-accent-contrast',
			'text_color'   => '--esx-text-color',
			'field_bg'     => '--esx-field-bg',
			'field_color'  => '--esx-field-color',
			'border_color' => '--esx-border',
		);
		foreach ( $colour_map as $att => $var ) {
			if ( empty( $atts[ $att ] ) ) {
				continue;
			}
			$safe = self::sanitize_color( (string) $atts[ $att ] );
			if ( '' !== $safe ) {
				$vars[] = $var . ':' . $safe;
			}
		}

		// Corner radius keyword → value.
		if ( ! empty( $atts['radius'] ) ) {
			$radius = self::radius_value( (string) $atts['radius'] );
			if ( '' !== $radius ) {
				$vars[] = '--esx-radius:' . $radius;
			}
		}

		// Width keyword → max-width.
		if ( ! empty( $atts['width'] ) ) {
			$width = self::width_value( (string) $atts['width'] );
			if ( '' !== $width ) {
				$vars[] = '--esx-width:' . $width;
			}
		}

		if ( empty( $vars ) ) {
			return '';
		}
		return ' style="' . esc_attr( implode( ';', $vars ) . ';' ) . '"';
	}

	/**
	 * Map a width keyword to a max-width. Empty = stylesheet default.
	 *
	 * @param string $keyword 'narrow' | 'wide' | 'full' | ''.
	 * @return string
	 */
	protected static function width_value( $keyword ) {
		switch ( strtolower( trim( (string) $keyword ) ) ) {
			case 'narrow':
				return '360px';
			case 'wide':
				return '640px';
			case 'full':
				return '100%';
			default:
				return '';
		}
	}

	/**
	 * Map a corner-radius keyword to a CSS length. Empty = leave the
	 * stylesheet default in place.
	 *
	 * @param string $keyword 'rounded' | 'pill' | 'square' | ''.
	 * @return string
	 */
	protected static function radius_value( $keyword ) {
		switch ( strtolower( trim( (string) $keyword ) ) ) {
			case 'square':
				return '0';
			case 'rounded':
				return '14px';
			case 'pill':
				return '999px';
			default:
				return ''; // 'default'/'' → no override.
		}
	}

	/**
	 * Permit only #hex or rgb()/rgba() colour values.
	 *
	 * @param string $value Raw colour.
	 * @return string Sanitised colour or empty string.
	 */
	protected static function sanitize_color( $value ) {
		$value = trim( (string) $value );

		// #rgb / #rgba / #rrggbb / #rrggbbaa
		if ( preg_match( '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}

		// rgb() / rgba() / hsl() / hsla()
		if ( preg_match( '#^(?:rgba?|hsla?)\(\s*[0-9.,\s%/-]+\)$#i', $value ) ) {
			return $value;
		}

		// A CSS custom property, e.g. var(--ast-global-color-0).
		//
		// This is what the block editor's colour palette hands back for THEME
		// colours (Astra, and any theme.json palette) rather than a hex — so
		// without this branch every theme-palette pick was silently rejected
		// and the colour appeared to "do nothing". The character class stays
		// tight (no ; { } ) so a value can't break out of the inline style
		// it gets interpolated into.
		if ( preg_match( '/^var\(\s*--[A-Za-z0-9_-]+\s*(?:,\s*[^;{}()]+)?\)$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Serialise a map of pre-sanitised data-* attributes to a string.
	 * Values are escaped with esc_attr; empty values are dropped.
	 *
	 * @param array $data Attribute map.
	 * @return string
	 */
	protected static function attrs( $data ) {
		$out = array();
		foreach ( $data as $k => $v ) {
			if ( '' === $v || null === $v ) {
				continue;
			}
			$out[] = esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return implode( ' ', $out );
	}

	/**
	 * A configuration hint shown only to editors, so a broken embed on a
	 * live page is invisible to visitors but obvious to the site owner.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	protected static function admin_hint( $message ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<p class="esx-form__hint" style="padding:10px 14px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">'
			. esc_html( $message ) . '</p>';
	}
}
