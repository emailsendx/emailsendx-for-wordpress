; EmailSendX for WordPress
=== EmailSendX for WordPress ===
Contributors: emailsendx
Tags: email marketing, newsletter, woocommerce, sync, contacts, customers, crm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your WordPress users and WooCommerce customers to EmailSendX — with custom field mapping, scheduled syncs, and a beautiful admin UI.

== Description ==

**EmailSendX for WordPress** is the official bridge between your WordPress site and your [EmailSendX](https://emailsendx.com) workspace. Connect once, then keep your contact lists in sync automatically — every new user, every new customer, every profile update flows straight into EmailSendX so your campaigns always target a fresh audience.

It works equally well for content sites, membership sites, and WooCommerce stores. WooCommerce is auto-detected — no extra setup, no extra add-on.

= Why EmailSendX for WordPress? =

* **Set it and forget it** — turn on auto-sync once, never touch a CSV again.
* **Mapping that makes sense** — drag your WordPress fields onto EmailSendX targets in a clean two-column UI.
* **Custom fields on the fly** — create new EmailSendX custom fields directly from the mapping screen.
* **WooCommerce-aware** — billing name, company, phone, lifetime spend, last order — all available as source fields.
* **Beautiful inside WP admin** — a premium dashboard experience that doesn't feel out of place next to your other plugins.

= Features =

* Sync WordPress users to EmailSendX
* WooCommerce customer sync (auto-detected when WooCommerce is active)
* Custom field mapping with merge-tag support (`{{contact.firstName}}`, `{{contact.custom.<key>}}`)
* Manual one-click sync, plus automatic sync on user/customer create + update
* Sync history with per-batch breakdown (created, updated, skipped, failed)
* Premium UI inside the WordPress admin — gradient header, polished cards, live progress
* Per-role and per-list filtering
* Built-in connection tester so you know your API key is good before you start

= Privacy =

This plugin sends data to EmailSendX (the SaaS service) using the API key you configure. You control what gets sent via the Mapping tab. No data leaves your site until you connect a key. See [emailsendx.com/privacy](https://emailsendx.com/privacy) for the SaaS data policy.

== Installation ==

1. Upload the `emailsendx-sync` folder to `/wp-content/plugins/`, or install through the WordPress Plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **EmailSendX → Settings** in the admin menu and paste your API key. You can find or create a key in your EmailSendX dashboard under **Settings → API keys**. See the [setup guide](https://emailsendx.com/docs/wordpress) for details.
4. Visit **EmailSendX → Mapping** to choose which WordPress / WooCommerce fields land where in EmailSendX.
5. Hit **Run sync now** on the **Sync** tab to push your existing users for the first time.

== Frequently Asked Questions ==

= Do I need a paid EmailSendX plan to use this plugin? =

No — the plugin works with any EmailSendX plan, including the free tier. Higher-volume syncs may run into rate limits on the free plan, in which case you'll see "skipped" rows in the sync log and a clear message in the EmailSendX dashboard.

= Will this plugin overwrite contacts I already have in EmailSendX? =

The plugin upserts contacts by email. If a contact already exists, only the fields you've mapped get updated — every other field on the EmailSendX side is left alone. Email itself is never modified.

= Does it work with WooCommerce? =

Yes. WooCommerce is auto-detected on activation; if it's installed and active, you'll see a second source tab labelled "WooCommerce Customers" with billing fields, company, phone, lifetime spend, and last order date as mappable source fields. You can sync WP users, WooCommerce customers, or both.

= How do I sync only certain user roles? =

Open **EmailSendX → Settings** and pick a role from the **Sync role** dropdown. Leave it blank to sync every role. Role filtering applies to both manual and automatic syncs.

= What happens when a user is deleted in WordPress? =

By default the plugin does **not** delete or unsubscribe contacts in EmailSendX when a WordPress user is deleted — you may still want to email them, so deletion is intentionally not mirrored. To stop emailing someone, unsubscribe or suppress them inside EmailSendX.

= Can I create EmailSendX custom fields without leaving WordPress? =

Yes. On the Mapping tab, pick **+ Create new custom field…** in any target dropdown. A small dialog will let you set a key, label, and type, and the new field becomes available everywhere on your workspace immediately.

= Where do I find sync history? =

The **Sync** tab shows the last few sync runs with totals (created, updated, skipped, failed). Click any run for the per-batch breakdown including any error messages returned by the EmailSendX API.

== Screenshots ==

1. The Settings tab with the API key field and connection tester.
2. The Mapping tab — match WordPress fields to EmailSendX targets.
3. The Sync tab with live progress and history.

== Changelog ==

= 1.2.1 =
* Maintenance release; plugin header and readme stable tag aligned on version 1.2.1.

= 1.1.0 =
* Sync tab redesigned around a single hero CTA — pick a source, pick a list, push contacts in one click.
* Segmented source picker (WP Users / WooCommerce Customers) with live counts.
* Dynamic button label that updates as you switch source or target list.
* Settings tab compacted into a connection status row, single-row default-list picker, and side-by-side sync behavior controls.
* Plugin renamed to "EmailSendX for WordPress" in user-facing locations (slug unchanged).

= 1.0.0 =
* Initial release.
* WordPress user sync.
* WooCommerce customer sync (auto-detected).
* Custom field mapping with on-the-fly field creation.
* Manual + automatic sync.
* Sync history with per-batch breakdown.
* Premium admin UI.

== Upgrade Notice ==

= 1.2.1 =
Recommended update; version metadata and release notes refreshed.

= 1.1.0 =
Redesigned Sync tab puts the import action front and center; Settings page is now compact and premium.

= 1.0.0 =
First public release of EmailSendX for WordPress.
