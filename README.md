# MCP Abilities - Brevo

Brevo (Sendinblue) integration for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-brevo)](https://github.com/bjornfix/mcp-abilities-brevo/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 1.0.2
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This add-on plugin exposes Brevo (formerly Sendinblue) functionality through MCP (Model Context Protocol). Your AI assistant can manage contacts, lists, and send emails directly via the Brevo API.

**Part of the [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) ecosystem.**

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin
- [Brevo](https://wordpress.org/plugins/mailin/) plugin (with API key configured)

## Installation

1. Install the required plugins (Abilities API, MCP Adapter, Brevo)
2. Configure the Brevo plugin with your API key
3. Download the latest release from [Releases](https://github.com/bjornfix/mcp-abilities-brevo/releases)
4. Upload via WordPress Admin > Plugins > Add New > Upload Plugin
5. Activate the plugin

## Abilities (22)

| Ability | Description |
|---------|-------------|
| `brevo/list-contacts` | List contacts with pagination |
| `brevo/get-contact` | Get a single contact by email or ID |
| `brevo/create-contact` | Create a contact |
| `brevo/update-contact` | Update a contact |
| `brevo/delete-contact` | Delete a contact |
| `brevo/list-lists` | List contact lists |
| `brevo/get-list` | Get a list by ID |
| `brevo/update-list` | Update list metadata |
| `brevo/delete-list` | Delete a list |
| `brevo/create-list` | Create a list |
| `brevo/list-attributes` | List contact attributes |
| `brevo/create-attribute` | Create a custom attribute |
| `brevo/update-attribute` | Update a custom attribute |
| `brevo/delete-attribute` | Delete a custom attribute |
| `brevo/list-senders` | List configured senders |
| `brevo/list-templates` | List email templates |
| `brevo/get-template` | Get template details |
| `brevo/add-to-list` | Add contacts to a list |
| `brevo/remove-from-list` | Remove contacts from a list |
| `brevo/send-email` | Send transactional email |
| `brevo/list-campaigns` | List campaigns |
| `brevo/send-campaign` | Send a campaign immediately |

## Usage Examples

### List all contact lists

```json
{
  "ability_name": "brevo/list-lists",
  "parameters": { "limit": 50 }
}
```

### Create a contact and add to list

```json
{
  "ability_name": "brevo/create-contact",
  "parameters": {
    "email": "user@example.com",
    "listIds": [5],
    "attributes": {
      "FIRSTNAME": "John",
      "LASTNAME": "Doe"
    }
  }
}
```

### Send transactional email

```json
{
  "ability_name": "brevo/send-email",
  "parameters": {
    "to": [{"email": "recipient@example.com", "name": "Recipient"}],
    "sender": {"email": "sender@example.com", "name": "Sender"},
    "subject": "Welcome!",
    "htmlContent": "<html><body><h1>Hello!</h1></body></html>"
  }
}
```

## Changelog

### 1.0.2
- Added: list/get/update/delete abilities for Brevo lists and attributes
- Added: sender and template read abilities
- Added: campaign list/send abilities

### 1.0.1
- Improve API error handling and reuse permission callback

### 1.0.0
- Initial release with 12 abilities
- Contacts: list, get, create, update, delete
- Lists: list, create, add-to-list, remove-from-list
- Email: send transactional, list campaigns, send campaign

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
- [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
