# MCP Abilities - Brevo

Brevo (Sendinblue) integration for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-brevo)](https://github.com/bjornfix/mcp-abilities-brevo/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 1.0.0
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

## Abilities (12)

### Contacts
| Ability | Description |
|---------|-------------|
| `brevo/list-contacts` | List all contacts with pagination |
| `brevo/get-contact` | Get a single contact by email or ID |
| `brevo/create-contact` | Create a new contact |
| `brevo/update-contact` | Update contact attributes |
| `brevo/delete-contact` | Delete a contact |

### Lists
| Ability | Description |
|---------|-------------|
| `brevo/list-lists` | Get all contact lists |
| `brevo/create-list` | Create a new list |
| `brevo/add-to-list` | Add contacts to a list |
| `brevo/remove-from-list` | Remove contacts from a list |

### Email
| Ability | Description |
|---------|-------------|
| `brevo/send-email` | Send transactional email |
| `brevo/list-campaigns` | List email campaigns |
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
