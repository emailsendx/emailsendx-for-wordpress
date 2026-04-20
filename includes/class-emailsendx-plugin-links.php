<?php
/**
 * Plugin row action + meta links on the WordPress Plugins screen.
 *
 * Prepends quick-access admin links (Sync now / Settings / Mapping)
 * to the plugin's row actions, and appends Docs / Support / View log
 * links to the plugin row meta column.
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
 * Class EmailSendX_Plugin_Links
 */
class EmailSendX_Plugin_Links {

	/**
	 * Wire the plugin-screen filters.
	 */
	public function __construct() {
		add_filter( 'plugin_action_links_' . EMAILSENDX_SYNC_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Prepend Sync / Settings / Mapping quick-links to the action column.
	 *
	 * @param array $links Existing links (Deactivate / Edit / …).
	 * @return array
	 */
	public function action_links( $links ) {
		$base = admin_url( 'admin.php?page=emailsendx-sync' );
		$new  = array(
			'sync'     => '<a href="' . esc_url( $base . '&tab=sync' ) . '">' . esc_html__( 'Sync now', 'emailsendx-sync' ) . '</a>',
			'settings' => '<a href="' . esc_url( $base . '&tab=settings' ) . '">' . esc_html__( 'Settings', 'emailsendx-sync' ) . '</a>',
			'mapping'  => '<a href="' . esc_url( $base . '&tab=mapping' ) . '">' . esc_html__( 'Mapping', 'emailsendx-sync' ) . '</a>',
		);
		return array_merge( $new, is_array( $links ) ? $links : array() );
	}

	/**
	 * Append Docs / Support / View log to the plugin row meta column.
	 *
	 * @param array  $meta        Existing meta links.
	 * @param string $plugin_file The plugin's basename being filtered.
	 * @return array
	 */
	public function row_meta( $meta, $plugin_file ) {
		if ( $plugin_file !== EMAILSENDX_SYNC_BASENAME ) {
			return $meta;
		}
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$log_url = admin_url( 'admin.php?page=emailsendx-sync&tab=log' );

		$meta[] = '<a href="' . esc_url( 'https://emailsendx.com/docs/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'emailsendx-sync' ) . '</a>';
		$meta[] = '<a href="' . esc_url( 'https://emailsendx.com/support' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'emailsendx-sync' ) . '</a>';
		$meta[] = '<a href="' . esc_url( $log_url ) . '">' . esc_html__( 'View log', 'emailsendx-sync' ) . '</a>';

		return $meta;
	}
}
