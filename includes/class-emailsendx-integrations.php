<?php
/**
 * EmailSendX Sync — page-builder / plugin integrations registry.
 *
 * A single source of truth for every builder EmailSendX plugs into. The
 * Integrations admin tab renders this list (name, logo, live status); as
 * new adapters ship (Gutenberg blocks, Elementor, Divi, …) they're added
 * here as one array entry — the UI needs no further changes.
 *
 * Each entry declares how to DETECT the host builder (a constant/class/
 * function check) and whether OUR adapter has shipped yet. The status a
 * card shows is derived from those two facts:
 *
 *   shipped + detected      → "Active"          (green)  — live right now
 *   shipped + not detected  → "Install <name>"  (grey)   — ready, builder absent
 *   not shipped + detected  → "Coming soon"     (blue)   — builder here, adapter pending
 *   not shipped + absent    → "Planned"         (grey)   — on the roadmap
 *
 * Logos: the `logo` field is a URL to a bundled image when one exists;
 * otherwise the UI falls back to a coloured monogram tile built from
 * `monogram` + `color`, so a card always looks intentional.
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
 * Class EmailSendX_Integrations
 */
class EmailSendX_Integrations {

	/**
	 * The full integrations catalogue. Order = display order (shipped +
	 * active first). Filterable so add-ons can register their own.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_integrations() {
		$has_gutenberg = function_exists( 'register_block_type' );

		$list = array(
			array(
				'key'         => 'wpbakery',
				'name'        => 'WPBakery Page Builder',
				'monogram'    => 'WB',
				'color'       => '#4E8CFF',
				'logo'        => '',
				'description' => __( 'Drag EmailSendX Form & Newsletter elements straight into your WPBakery layouts.', 'emailsendx-sync' ),
				'detected'    => defined( 'WPB_VC_VERSION' ),
				'version'     => defined( 'WPB_VC_VERSION' ) ? WPB_VC_VERSION : '',
				'shipped'     => true,
				'url'         => 'https://wpbakery.com/',
			),
			array(
				'key'         => 'gutenberg',
				'name'        => 'Block Editor (Gutenberg)',
				'monogram'    => 'G',
				'color'       => '#1E1E1E',
				'logo'        => '',
				'description' => __( 'Native blocks for the default WordPress editor.', 'emailsendx-sync' ),
				'detected'    => $has_gutenberg,
				'version'     => '',
				'shipped'     => false,
				'url'         => 'https://wordpress.org/documentation/article/wordpress-block-editor/',
			),
			array(
				'key'         => 'elementor',
				'name'        => 'Elementor',
				'monogram'    => 'E',
				'color'       => '#92003B',
				'logo'        => '',
				'description' => __( 'Widgets for the Elementor drag-and-drop builder.', 'emailsendx-sync' ),
				'detected'    => defined( 'ELEMENTOR_VERSION' ),
				'version'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'shipped'     => false,
				'url'         => 'https://elementor.com/',
			),
			array(
				'key'         => 'divi',
				'name'        => 'Divi Builder',
				'monogram'    => 'D',
				'color'       => '#7B4FFF',
				'logo'        => '',
				'description' => __( 'Modules for the Divi builder & theme.', 'emailsendx-sync' ),
				'detected'    => defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' ),
				'version'     => defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : '',
				'shipped'     => false,
				'url'         => 'https://www.elegantthemes.com/gallery/divi/',
			),
			array(
				'key'         => 'beaver',
				'name'        => 'Beaver Builder',
				'monogram'    => 'BB',
				'color'       => '#EF7C2A',
				'logo'        => '',
				'description' => __( 'Modules for the Beaver Builder canvas.', 'emailsendx-sync' ),
				'detected'    => class_exists( 'FLBuilder' ) || class_exists( 'FLBuilderModel' ),
				'version'     => '',
				'shipped'     => false,
				'url'         => 'https://www.wpbeaverbuilder.com/',
			),
		);

		/**
		 * Allow other code (or future bundled adapters) to register or
		 * adjust integrations. ShaonPro.
		 *
		 * @param array $list Integrations catalogue.
		 */
		$list = apply_filters( 'emailsendx_integrations', $list );

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Derive the display status for one integration entry.
	 *
	 * @param array $integration One catalogue entry.
	 * @return array{state:string, label:string, pill:string}
	 */
	public static function status( $integration ) {
		$shipped  = ! empty( $integration['shipped'] );
		$detected = ! empty( $integration['detected'] );
		$name     = isset( $integration['name'] ) ? (string) $integration['name'] : '';

		if ( $shipped && $detected ) {
			return array(
				'state' => 'active',
				'label' => __( 'Active', 'emailsendx-sync' ),
				'pill'  => 'esx-pill-ok',
			);
		}
		if ( $shipped && ! $detected ) {
			return array(
				'state' => 'ready',
				'label' => sprintf(
					/* translators: %s: builder name. */
					__( 'Ready — install %s', 'emailsendx-sync' ),
					$name
				),
				'pill'  => 'esx-pill-info',
			);
		}
		if ( ! $shipped && $detected ) {
			return array(
				'state' => 'coming_soon',
				'label' => __( 'Detected — coming soon', 'emailsendx-sync' ),
				'pill'  => 'esx-pill-info',
			);
		}
		return array(
			'state' => 'planned',
			'label' => __( 'Planned', 'emailsendx-sync' ),
			'pill'  => 'esx-pill-warn',
		);
	}

	/**
	 * Count how many integrations are live right now (shipped + detected).
	 *
	 * @return int
	 */
	public static function active_count() {
		$n = 0;
		foreach ( self::get_integrations() as $i ) {
			if ( ! empty( $i['shipped'] ) && ! empty( $i['detected'] ) ) {
				$n++;
			}
		}
		return $n;
	}
}
