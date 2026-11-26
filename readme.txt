=== Campaign Bandit ===
Contributors: dragcampaignbandit
Tags: popup, lead generation, forms, block editor, campaigns
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build popups and inline forms with the block editor, target them by page and device, and keep every lead in your own WordPress.

== Description ==

**Every other popup plugin asks you to learn its editor.** Campaign Bandit asks you to use the one you already know.

A campaign is a popup or an inline block, built in the WordPress block editor with the same groups, headings, images, buttons and columns you use on every page. No separate designer, no shortcode soup, no second interface with its own idea of what a column is. If you can build a page, you can build a popup — and it will look like the rest of your site, because it is made of the same things.

Leads go into your own database. Not ours, not anybody's. There is no account to create, no API key to paste, and no service to sign up for.

= Ready to get started? =

* [campaignbandit.com](https://campaignbandit.com/?utm_source=wordpress-org&utm_medium=readme&utm_campaign=description) – what it does, and who it is for
* [Documentation](https://campaignbandit.com/docs/?utm_source=wordpress-org&utm_medium=readme&utm_campaign=description) – setup, targeting, forms and delivery
* [How it works](https://campaignbandit.com/how-it-works/?utm_source=wordpress-org&utm_medium=readme&utm_campaign=description) – the short version, with pictures
* [Pricing](https://campaignbandit.com/pricing/?utm_source=wordpress-org&utm_medium=readme&utm_campaign=description) – what the paid add-on costs, if you ever want it

= Design it with blocks, not with a form builder =

Open a campaign and you are in Gutenberg. Use core blocks for the layout and Campaign Bandit's own blocks for the parts a form needs: email, text, long text, phone, date, choice and consent fields. Design a thank-you screen that replaces the form once somebody sends it, so the moment after conversion is designed rather than default.

Eight starter patterns ship with the plugin — four image-led, four type-only — so the first campaign is a few edits rather than a blank canvas.

= Say exactly where and when it shows =

* **Page rules** – the front page, a single post or page, a child of a chosen page, or a URL path with wildcards
* **Device rules** – mobile, tablet or desktop
* **Triggers** – after a number of seconds, or when somebody clicks a link or button you choose
* **Frequency** – the same visitor sees a campaign at most once every seven days
* **Page exclusions** – the login and admin pages are left alone unless you say otherwise, and the cart and checkout too when WooCommerce is running

Rules that depend on the visitor are decided in the browser, so a cached page cannot freeze one visitor's campaign for everyone else. Rules that depend on the page are decided on the server, so a page that can never match renders nothing at all.

= Keep every lead, and prove where it came from =

Leads land in **Campaign Bandit › Leads** with the campaign and version that produced them. Filter by campaign or date, search by name or email, export the lot as CSV. Each lead carries the consent text that was on screen when it was submitted, so you can show what somebody actually agreed to.

= Your visitors' data stays on your site =

This is the part most popup plugins cannot say plainly, so here it is in full:

* Campaign Bandit calls **no external service** of its own, and nothing about your visitors is sent anywhere
* **No account**, no API key, no sign-up
* IP addresses are **never stored**
* Visitor state — which campaigns somebody has seen or dismissed — lives in their own browser, not on your server, and holds no personal data
* Leads are included in WordPress's own personal data **export and erase** tools
* Leads are deleted automatically after a retention period you choose
* If your site uses a consent tool, Campaign Bandit reads it: the WP Consent API, Complianz, CookieYes and Cookiebot. Campaigns still show, because showing a campaign needs no consent — what waits is recording where a lead came from

One optional download exists, described under **External services** below, and it is off until you turn it on.

= Everything above is in the free plugin =

No trial, no expiry, no feature locked behind a key, no nag to upgrade before you can finish what you started. The free plugin is the whole product for one site.

= Campaign Bandit Pro, if you outgrow it =

A separate add-on, sold at [campaignbandit.com](https://campaignbandit.com/pricing/?utm_source=wordpress-org&utm_medium=readme&utm_campaign=pro) and not required for anything above. It adds:

* **Slide-ins and sticky bars** alongside popups and inline campaigns
* **Scroll, exit intent, hover and custom event triggers**
* **Scheduling, URL parameter and country rules**, several rule sets, and saved conditions you reuse across campaigns
* **Webhook delivery** to your CRM, Zapier or Make, with retries and a delivery log that shows every attempt
* **Versions and rollback**, and an explanation of why each campaign showed for any given lead
* **Configurable frequency**, priority between campaigns, and suppression after a dismissal or a conversion

For agencies, the Agency plans add a hub that runs campaigns across client sites from one WordPress — publishing to chosen sites, per-site settings, and every lead from every site in one place. That is what [campaignbandit.com/for-agencies](https://campaignbandit.com/for-agencies/?utm_source=wordpress-org&utm_medium=readme&utm_campaign=agencies) is about.

== Installation ==

1. Install and activate the plugin.
2. Go to **Campaign Bandit › Campaigns** and choose **New campaign**.
3. Start blank or pick a starter pattern, then design it in the block editor.
4. Set where it shows under **Display conditions**, and when under **Triggers**.
5. Publish. The campaign goes live on your site.

Leads arrive under **Campaign Bandit › Leads**. There is nothing else to configure and nothing to connect.

== Frequently Asked Questions ==

= Do I need an account anywhere? =

No. The plugin works entirely on your own site and never asks you to register for anything.

= Where are leads stored? =

In your own WordPress database, in the plugin's own table. Export them as CSV whenever you like. They are included in WordPress's personal data export and erase tools, and deleted automatically after a retention period you choose.

= Does it slow my site down? =

The front-end script is about 13 KB gzipped, and it loads only when the site has a campaign live. A site with nothing published loads nothing at all. Inline campaigns go further: a page whose rules can never match renders no markup for it.

= Does it work with caching? =

Yes. Rules that depend on the visitor are decided in the browser, so a cached page cannot freeze one visitor's campaign for everybody else.

= Will it respect my cookie consent tool? =

Yes. Campaign Bandit reads the WP Consent API and several consent plugins directly. Campaigns still show, because showing a campaign needs no consent; what waits is recording which campaign and which source a lead came from.

= Can I style the popup to match my theme? =

It is built from your theme's own blocks, so it inherits your theme's styles by default, and you change anything you like in the block editor as you would on a page.

= Which formats does the free plugin have? =

Popups (modal) and inline campaigns. Slide-ins and sticky bars are in the paid add-on.

= Can I send leads to my CRM or to Zapier? =

Not with the free plugin, which stores leads on your site and exports CSV. Webhook delivery, with retries and a delivery log, is in the paid add-on.

= I run sites for clients. Can I manage all of them from one place? =

That is what the Agency plans are for: one WordPress acts as a hub and publishes campaigns to connected client sites, which run this free plugin. See [campaignbandit.com/for-agencies](https://campaignbandit.com/for-agencies/?utm_source=wordpress-org&utm_medium=readme&utm_campaign=faq).

= Where do I report a bug or ask for a feature? =

The free plugin's source is at [github.com/campaignbandit/campaign-bandit](https://github.com/campaignbandit/campaign-bandit), and issues there are read.

== External services ==

Campaign Bandit contacts no external service on its own, and never sends anything about your visitors anywhere.

One optional download exists. Under Campaign Bandit › Settings › Country targeting you can choose to use a country database, and only then does the site download one file from DB-IP:

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

PHP is in `src/`, autoloaded by namespace, and is not minified or obfuscated anywhere. The source also lives at [github.com/campaignbandit/campaign-bandit](https://github.com/campaignbandit/campaign-bandit).

== Changelog ==

= 1.0.0 =
* First release.
