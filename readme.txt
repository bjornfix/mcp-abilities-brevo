=== MCP Abilities - Brevo ===
Contributors: devenia
Tags: mcp, brevo, sendinblue, email, contacts
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Brevo (Sendinblue) integration for WordPress via MCP.

== Description ==

This add-on plugin exposes Brevo (formerly Sendinblue) functionality through MCP (Model Context Protocol). Your AI assistant can manage contacts, lists, and send emails directly via the Brevo API.

Part of the MCP Expose Abilities ecosystem.

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter, Brevo)
2. Configure the Brevo plugin with your API key
3. Download and install this plugin
4. Activate the plugin

== Changelog ==

= 1.0.1 =
* Improve API error handling and reuse permission callback

= 1.0.0 =
* Initial release with 12 abilities
* Contacts: list, get, create, update, delete
* Lists: list, create, add-to-list, remove-from-list
* Email: send transactional, list campaigns, send campaign
