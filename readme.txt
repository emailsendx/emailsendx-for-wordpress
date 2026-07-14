; EmailSendX for WordPress
=== EmailSendX for WordPress ===
Contributors: emailsendx
Tags: email marketing, newsletter, signup form, elementor, gutenberg
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WordPress users and WooCommerce customers to EmailSendX, and add opt-in forms and newsletter boxes with native WPBakery, Elementor and Block Editor elements.

== Description ==

**EmailSendX for WordPress** is the official bridge between your WordPress site and your [EmailSendX](https://emailsendx.com) workspace. Connect once, then keep your contact lists in sync automatically — every new user, every new customer, every profile update flows straight into EmailSendX so your campaigns always target a fresh audience.

It works equally well for content sites, membership sites, and WooCommerce stores. WooCommerce is auto-detected — no extra setup, no extra add-on.

**New in 1.3.0 — grow the list, don't just sync it.** Drop an EmailSendX opt-in form or a newsletter signup box straight onto any page, using the builder you already use. The fields, double opt-in and spam protection all come from the form you built in EmailSendX, so there's nothing to rebuild and nothing to keep in step.

= Works with your page builder =

The same two elements are available everywhere, and they render identically no matter where you place them:

* **WPBakery Page Builder** — "EmailSendX Form" and "EmailSendX Newsletter" elements
* **Elementor** — matching widgets, with Elementor's own margin/padding/motion controls on top
* **Block Editor (Gutenberg)** — native blocks with a live preview
* **Spectra** — the blocks work inside Spectra layouts too, since Spectra builds on the block editor
* **Anywhere else** — paste the `[emailsendx_form]` or `[emailsendx_newsletter]` shortcode

= Why EmailSendX for WordPress? =

* **Set it and forget it** — turn on auto-sync once, never touch a CSV again.
* **Capture, don't just sync** — put a real opt-in form on the page in a couple of clicks.
* **Match your site** — 16 style controls (width, size, spacing, field style, corner radius, colours, button style) so the form looks like it belongs, not like a plugin.
* **Mapping that makes sense** — drag your WordPress fields onto EmailSendX targets in a clean two-column UI.
* **WooCommerce-aware** — billing name, company, phone, lifetime spend, last order — all available as source fields.
* **Beautiful inside WP admin** — a premium dashboard experience that doesn't feel out of place next to your other plugins.

= Features =

* Embed any EmailSendX opt-in form — fields, double opt-in and spam protection come from the form itself
* Quick newsletter signup box that adds subscribers straight to a contact list
* Native elements for WPBakery, Elementor, the Block Editor and Spectra — plus shortcodes
* A shared style system (layout, width, size, spacing, field style, radius, labels, colours, button style/alignment) that works the same in every builder
* Integrations screen showing which builders are detected and live on your site
* Sync WordPress users to EmailSendX
* WooCommerce customer sync (auto-detected when WooCommerce is active)
* Custom field mapping with merge-tag support (`{{contact.firstName}}`, `{{contact.custom.<key>}}`)
* Manual one-click sync, plus automatic sync on user/customer create + update
* Sync history with per-batch breakdown (created, updated, skipped, failed)
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

= Which page builders are supported? =

WPBakery Page Builder, Elementor, and the Block Editor (Gutenberg). Spectra works too, because it builds on the block editor. Anywhere else — a text widget, a theme template, another builder — you can paste the `[emailsendx_form]` or `[emailsendx_newsletter]` shortcode. The Integrations tab shows you which builders are detected and live on your site.

= Do I have to rebuild my form for each builder? =

No. Every builder renders the same underlying form, so a form placed with Elementor and the same form placed with WPBakery produce identical output. Build the form once in EmailSendX and pick it from a dropdown.

= What's the difference between the Form element and the Newsletter element? =

The **Form** element embeds a form you built in EmailSendX — it uses that form's fields, double opt-in and spam protection. The **Newsletter** element is a quick email-capture box that adds people straight to a contact list; it's single opt-in. If you need confirmed opt-in, use a Form.

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

= 1.3.0 =
The plugin now grows your list as well as syncing it: opt-in forms and newsletter boxes, with native elements for every major builder.

**Added**

* Embed any EmailSendX opt-in form on your site. Fields, double opt-in, reCAPTCHA and rate limiting all come from the form itself, so nothing is duplicated or has to be kept in step.
* Newsletter signup box that adds subscribers straight to a contact list (single opt-in).
* WPBakery Page Builder elements — "EmailSendX Form" and "EmailSendX Newsletter".
* Elementor widgets, with Elementor's own layout and motion controls available on top.
* Block Editor (Gutenberg) blocks, with a live preview while you edit. These also work inside Spectra, which builds on the block editor.
* `[emailsendx_form]` and `[emailsendx_newsletter]` shortcodes for use anywhere else. Every builder renders through these, so output is identical no matter how a form was placed.
* A shared style system available in all builders: alignment, width, size, field spacing, field style (outlined / filled / underline), corner radius, label visibility, field/border/text/accent/button colours, button style, button alignment and full-width button.
* Colour controls accept your theme's palette, not just hex values — pick "Brand" from your theme's colours and the form follows it, including when the theme later changes that colour.
* The block inserter shows a real preview of the form before you place it.
* New admin tabs — Forms and Newsletter (each listing your forms/lists with a ready-to-paste shortcode) and Integrations, which shows which builders are detected and live on this site.

**Fixed**

* Form fields no longer switch to a dark background based on the visitor's operating-system theme. An embedded form now follows the page it sits on, not the visitor's OS.
* Style options now apply to every field and the button. Some themes style `input` more specifically than the plugin did, which let a theme silently override your choices (a corner radius would reach the textarea but not the text inputs).
* The submit button and the email field now render at exactly the same height in the inline newsletter layout.

**Note**

* The form picker requires the `/api/v1/forms` endpoint on your EmailSendX instance. If you self-host EmailSendX, update it before upgrading, or the picker will report that no forms were found.

= 1.2.2 =
* Add UI screenshots to the README

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

= 1.3.0 =
Adds opt-in forms and newsletter boxes, with native elements for WPBakery, Elementor, the Block Editor and Spectra. Also fixes form fields turning dark on some visitors' machines, and style options being overridden by the theme. Self-hosted EmailSendX instances should update to an EmailSendX build that includes /api/v1/forms first.

= 1.2.2 =
Add UI screenshots to the README

= 1.2.1 =
Recommended update; version metadata and release notes refreshed.

= 1.1.0 =
Redesigned Sync tab puts the import action front and center; Settings page is now compact and premium.

= 1.0.0 =
First public release of EmailSendX for WordPress.
