<?php
/**
 * EmailSendX Sync — page-builder / plugin integrations registry.
 *
 * A single source of truth for every builder EmailSendX plugs into. The
 * Integrations admin tab renders this list (name, logo, live status); as
 * new adapters ship (Gutenberg blocks, Spectra, …) they're added
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
	 * Where each card's "Learn more" points.
	 *
	 * These deliberately link to OUR docs rather than the builder's own
	 * website — sending a customer who is mid-setup off to elementor.com is
	 * sending them out of the product and out of the funnel. Our page explains
	 * the EmailSendX integration and links onward to the builder if they need
	 * it. Always emailsendx.com (never the configured API base): a self-hosted
	 * EmailSendX instance serves the app, not the documentation.
	 */
	const DOCS_BASE = 'https://emailsendx.com/docs/integrations/';


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
				'logo'        => EMAILSENDX_SYNC_URL . 'assets/img/integrations/wpbakery.svg',
				'description' => __( 'Drag EmailSendX Form & Newsletter elements straight into your WPBakery layouts.', 'emailsendx-sync' ),
				'detected'    => defined( 'WPB_VC_VERSION' ),
				'version'     => defined( 'WPB_VC_VERSION' ) ? WPB_VC_VERSION : '',
				'shipped'     => true,
				'url'         => self::DOCS_BASE . 'wpbakery',
			),
			array(
				'key'         => 'elementor',
				'name'        => 'Elementor',
				'monogram'    => 'E',
				'color'       => '#92003B',
				'logo'        => EMAILSENDX_SYNC_URL . 'assets/img/integrations/elementor.svg',
				'description' => __( 'Drag EmailSendX Form & Newsletter widgets straight into your Elementor layouts.', 'emailsendx-sync' ),
				'detected'    => defined( 'ELEMENTOR_VERSION' ),
				'version'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'shipped'     => true,
				'url'         => self::DOCS_BASE . 'elementor',
			),
			array(
				'key'         => 'gutenberg',
				'name'        => 'Block Editor (Gutenberg)',
				'monogram'    => 'G',
				'color'       => '#21759B',
				'logo'        => EMAILSENDX_SYNC_URL . 'assets/img/integrations/gutenberg.svg',
				'description' => __( 'Native EmailSendX blocks for the default WordPress editor.', 'emailsendx-sync' ),
				'detected'    => $has_gutenberg,
				'version'     => '',
				'shipped'     => true,
				'url'         => self::DOCS_BASE . 'block-editor',
			),
			array(
				'key'         => 'spectra',
				'name'        => 'Spectra',
				'monogram'    => 'S',
				'color'       => '#6104FF',
				'logo'        => EMAILSENDX_SYNC_URL . 'assets/img/integrations/spectra.svg',
				'description' => __( 'Spectra builds on the block editor, so the EmailSendX blocks work inside Spectra layouts too.', 'emailsendx-sync' ),
				'detected'    => defined( 'UAGB_VER' ),
				'version'     => defined( 'UAGB_VER' ) ? UAGB_VER : '',
				'shipped'     => true,
				'url'         => self::DOCS_BASE . 'spectra',
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

		// Labels are deliberately SHORT — they render as a dot + word in the
		// card footer. A long label (the old "Ready — install <builder>")
		// competed with the card title and wrapped it onto three lines.
		if ( $shipped && $detected ) {
			return array(
				'state' => 'active',
				'tone'  => 'active',
				'label' => __( 'Active', 'emailsendx-sync' ),
			);
		}
		if ( $shipped && ! $detected ) {
			// We support it; the builder just isn't running (not installed,
			// or installed but deactivated).
			return array(
				'state' => 'ready',
				'tone'  => 'idle',
				'label' => __( 'Not active', 'emailsendx-sync' ),
			);
		}
		if ( ! $shipped && $detected ) {
			return array(
				'state' => 'coming_soon',
				'tone'  => 'soon',
				'label' => __( 'Coming soon', 'emailsendx-sync' ),
			);
		}
		return array(
			'state' => 'planned',
			'tone'  => 'idle',
			'label' => __( 'Planned', 'emailsendx-sync' ),
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
