=== MCP Abilities - Brevo ===
Contributors: basicus
Tags: mcp, brevo, sendinblue, email, wonderpush
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.10
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Brevo (Sendinblue) integration for WordPress via MCP.

== Description ==

This add-on plugin exposes Brevo (formerly Sendinblue) functionality through MCP (Model Context Protocol). Your AI assistant can manage contacts, lists, folders, WordPress signup forms, WonderPush prompt localization, senders, templates, webhooks, campaigns, and transactional email directly via Brevo.

It also includes a guarded generic Brevo API request ability for the full Brevo v3 API surface, including product areas that do not yet need a dedicated wrapper.

Part of the MCP Expose Abilities ecosystem.

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter, Brevo)
2. Configure the Brevo plugin with your API key
3. Download and install this plugin
4. Activate the plugin

== Changelog ==

= 1.0.10 =
* Fixed: Remove a stale campaign-read inventory entry that was not a registered ability

= 1.0.9 =
* Changed: Use the current site title as the default language-list prefix
* Changed: Replace private translation-option discovery with the public `mcp_brevo_site_languages` filter
* Changed: Align public plugin identity and documentation with the canonical Devenia distribution

= 1.0.8 =
* Fixed: WonderPush localization now also patches Brevo push initialization and audits required subscription-bell text coverage

= 1.0.7 =
* Fixed: Language-audience list lookup now respects Brevo contact-list pagination limits

= 1.0.6 =
* Added: Language-audience audit for Brevo contact language attributes and per-language lists
* Added: Ability to ensure language-specific Brevo audiences from the site language registry
* Added: Contact upsert ability that stores the normalized language attribute and adds the contact to the matching language list

= 1.0.5 =
* Added: Runtime WonderPush localization abilities for prompt/widget text stored in WordPress options
* Added: Frontend WonderPush init-option merge for localized subscription bell, dialog, switch, and opt-in text
* Added: WonderPush localization audit against known site languages

= 1.0.4 =
* Added: Generic Brevo v3 API request ability for endpoints not yet wrapped by a typed ability
* Added: Account details ability
* Added: Folder management abilities
* Added: Official Brevo WordPress signup form CRUD and ensure abilities
* Added: Webhook management abilities
* Improved: PATCH and DELETE requests can send JSON bodies when the Brevo endpoint requires them

= 1.0.3 =
* Added: Sender, sender domain, template, campaign, list, and attribute operations

= 1.0.2 =
* Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking


= 1.0.1 =
* Improve API error handling and reuse permission callback

= 1.0.0 =
* Initial release with 12 abilities
* Contacts: list, get, create, update, delete
* Lists: list, create, add-to-list, remove-from-list
* Email: send transactional, list campaigns, send campaign
