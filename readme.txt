=== Campaign Bandit ===
Contributors: dragcampaignbandit
Tags: popup, lead generation, forms, block editor, campaigns
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build popups and inline forms with the block editor, target them by page and device, and keep every lead in your own WordPress.

== Description ==

Campaign Bandit builds conversion campaigns the same way you build a page: in the block editor, with the core blocks you already know. No separate designer, no shortcode soup, no second interface to learn.

A campaign is a popup or an inline block with a form in it. You decide where it shows, when it appears, and what happens when somebody fills it in. Leads are stored in your own database and exported as CSV whenever you want them.

= What you can build =

* **Modal popups and inline campaigns**, designed with core blocks: groups, headings, paragraphs, images, buttons and lists
* **Forms** from Campaign Bandit's own blocks: email, text, long text, phone, date, choice and consent fields
* **A designed thank-you screen** that replaces the form after someone sends it
* **Eight starter patterns** to begin from, half of them image-led and half type-only

= Where and when it shows =

* **Page rules**: the front page, a single post or page, a child of a page, or a URL path
* **Device rules**: mobile, tablet or desktop
* **Triggers**: after a number of seconds, or on a click
* **Frequency**: the same visitor sees a campaign at most once every seven days

= What happens to leads =

* Stored in your own WordPress, in your own database
* Exported as CSV
* Included in WordPress's own personal data export and erase tools
* Deleted automatically after a retention period you choose

= Privacy, in plain terms =

Campaign Bandit does not call any external service on its own, and nothing about your visitors is sent anywhere. Visitor state — which campaigns someone has seen or dismissed — is kept in their own browser, not on your server, and carries no personal data. IP addresses are never stored. If your site uses a consent tool, Campaign Bandit waits for consent before recording where a lead came from.

There is one optional download, described under "External services" below. It is off until you turn it on.

= A paid add-on exists =

Campaign Bandit Pro is a separate plugin, sold and updated outside WordPress.org, that adds slide-ins and sticky bars, more triggers and targeting rules, webhook delivery to your CRM, versions and rollback, and an agency hub for running campaigns across client sites.

This plugin is complete without it. Nothing here is a trial, nothing expires, and no feature is locked behind a key.

== Installation ==

1. Install and activate the plugin.
2. Go to **Campaign Bandit > Campaigns** and choose **New campaign**.
3. Start blank or pick a starter pattern, then design it in the block editor.
4. Set where it shows under **Display conditions**, and when under **Triggers**.
5. Publish. The campaign goes live on your site.

Leads arrive under **Campaign Bandit > Leads**.

== Frequently Asked Questions ==

= Do I need an account anywhere? =

No. The plugin works entirely on your own site and never asks you to register.

= Where are leads stored? =

In your own WordPress database, in the plugin's own table. You can export them as CSV, and they are included in WordPress's personal data export and erase tools.

= Does it slow my site down? =

The front-end script is about 13 KB gzipped, and it loads only when the site has a campaign live. A site with nothing published loads nothing at all. Inline campaigns go further: a page whose rules can never match renders no markup for it.

= Does it work with caching? =

Yes. Rules that depend on the visitor are decided in the browser, so a cached page cannot freeze one visitor's campaign for everybody else.

= Will it respect my cookie consent tool? =

Yes. Campaign Bandit reads the WP Consent API and several consent plugins directly. Campaigns still show, because showing a campaign needs no consent; what waits is recording which campaign and which source a lead came from.

= What does the paid add-on add? =

Slide-ins and sticky bars, scroll and exit-intent triggers, scheduling and country rules, webhook delivery with retries and a delivery log, campaign versions with rollback, and a hub for running campaigns across many sites. It is a separate plugin and is not required.

== External services ==

Campaign Bandit contacts no external service on its own, and never sends anything about your visitors anywhere.

One optional download exists. Under Campaign Bandit > Settings > Country targeting you can choose to use a country database, and only then does the site download one file from DB-IP:

* Service: DB-IP (https://db-ip.com), the free IP to Country Lite database.
* When: after you turn the setting on, and once a month after that, from your server on a scheduled task.
* What is sent: nothing but the request for the file itself. No visitor data, no site data, no identifiers.
* What is stored: the database is kept in your uploads folder and used on your own server to turn a visitor's IP address into a country code. The IP address itself is never stored.
* Turning the setting off deletes the downloaded database.
* Terms: https://db-ip.com/tos ; privacy policy: https://db-ip.com/legal/privacy ; the database is used under the Creative Commons Attribution 4.0 licence (https://creativecommons.org/licenses/by/4.0/).

Without this setting, country targeting uses a country header when your host or CDN already sends one, and nothing is downloaded.

If you connect this site to a Campaign Bandit hub, it talks to that hub and nowhere else. That connection only exists after you pair the site yourself, using a key you paste in, and you can disconnect it at any time.

== Development ==

The JavaScript in `build/` is compiled and minified. The source it is built from ships beside it, in `js/`, so every line of this plugin can be read as it was written:

* `js/runtime/` — the front-end runtime
* `js/editor/` — the campaign editor
* `js/blocks/` — the form blocks
* `js/conditions/` — the display conditions editor
* `js/explain/` — the "Explain this page" tool

PHP is in `src/`, autoloaded by namespace, and is not minified or obfuscated anywhere.

== Changelog ==

= 0.1.0 =
* First release.
