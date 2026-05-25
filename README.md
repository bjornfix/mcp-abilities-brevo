# MCP Abilities - Brevo

Brevo (Sendinblue) integration for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-brevo)](https://github.com/bjornfix/mcp-abilities-brevo/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 1.0.4
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This add-on plugin exposes Brevo (formerly Sendinblue) functionality through MCP (Model Context Protocol). Your AI assistant can manage contacts, lists, folders, WordPress signup forms, senders, templates, webhooks, campaigns, and transactional email directly through Brevo.

It also includes `brevo/api-request`, a guarded generic Brevo v3 API ability. That gives the assistant controlled access to the wider Brevo API surface when a product area does not yet have a dedicated wrapper.

**Part of the [MCP Expose Abilities](https://github.com/bjornfix/mcp-expose-abilities) ecosystem.**

This is one piece of a bigger open WordPress automation stack that lets AI agents do real operational work instead of leaving you stuck with manual CRM and email chores.

## Why This Is Cool

Brevo work is exactly the kind of task people postpone because it is repetitive and fiddly.

With this add-on, you can tell Codex or Claude to inspect contacts, lists, templates, campaigns, signup forms, webhooks, or transactional mail flows and then make the needed change without the usual admin-panel grind.

## Documentation

- [Core Plugin: MCP Expose Abilities](https://github.com/bjornfix/mcp-expose-abilities)
- [MCP Wiki Home](https://github.com/bjornfix/mcp-expose-abilities/wiki)
- [Why Teams Use It](https://github.com/bjornfix/mcp-expose-abilities/wiki/Why-Teams-Use-It)
- [Use Cases](https://github.com/bjornfix/mcp-expose-abilities/wiki/Use-Cases)
- [Brevo Add-On Guide](https://github.com/bjornfix/mcp-expose-abilities/wiki/Addon-Brevo)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)

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

## Brevo API Coverage

The typed abilities below cover the operational areas we use most often. `brevo/api-request` covers the rest of Brevo's v3 API directly, including Email API, Transactional SMS, Transactional WhatsApp, Marketing Campaigns, Contact Management, Events, Object Management, Accounts and Settings, Sales CRM, Conversations, Ecommerce, and Loyalty.

Use typed abilities first when one exists. Use `brevo/api-request` when Brevo adds a new endpoint or when a lower-use product area does not yet need a dedicated wrapper.

## Abilities (42)

| Ability | Description |
|---------|-------------|
| `brevo/api-request` | Call any Brevo v3 endpoint with the configured API key |
| `brevo/get-account` | Get Brevo account and plan details |
| `brevo/list-contacts` | List contacts with pagination |
| `brevo/get-contact` | Get a single contact by email or ID |
| `brevo/create-contact` | Create a contact |
| `brevo/update-contact` | Update a contact |
| `brevo/delete-contact` | Delete a contact |
| `brevo/list-folders` | List contact-list folders |
| `brevo/get-folder` | Get a contact-list folder |
| `brevo/create-folder` | Create a contact-list folder |
| `brevo/update-folder` | Update a contact-list folder |
| `brevo/delete-folder` | Delete a contact-list folder |
| `brevo/list-lists` | List contact lists |
| `brevo/get-list` | Get a list by ID |
| `brevo/update-list` | Update list metadata |
| `brevo/delete-list` | Delete a list |
| `brevo/create-list` | Create a list |
| `brevo/list-wordpress-forms` | List signup forms from the official Brevo WordPress plugin |
| `brevo/get-wordpress-form` | Get a signup form and shortcode |
| `brevo/create-wordpress-form` | Create a signup form |
| `brevo/update-wordpress-form` | Update a signup form |
| `brevo/delete-wordpress-form` | Delete a signup form |
| `brevo/ensure-wordpress-form` | Create or update a signup form by title |
| `brevo/list-attributes` | List contact attributes |
| `brevo/create-attribute` | Create a custom attribute |
| `brevo/update-attribute` | Update a custom attribute |
| `brevo/delete-attribute` | Delete a custom attribute |
| `brevo/list-senders` | List configured senders |
| `brevo/list-sender-domains` | List sender domains and authentication status |
| `brevo/create-sender` | Create a sender identity |
| `brevo/list-templates` | List email templates |
| `brevo/get-template` | Get template details |
| `brevo/list-webhooks` | List webhooks |
| `brevo/get-webhook` | Get webhook details |
| `brevo/create-webhook` | Create a webhook |
| `brevo/update-webhook` | Update a webhook |
| `brevo/delete-webhook` | Delete a webhook |
| `brevo/add-to-list` | Add contacts to a list |
| `brevo/remove-from-list` | Remove contacts from a list |
| `brevo/send-email` | Send transactional email |
| `brevo/get-campaign` | Get an email campaign |
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

### Create or update a Brevo signup form

```json
{
  "ability_name": "brevo/ensure-wordpress-form",
  "parameters": {
    "title": "Devenia Send waitlist",
    "listIds": [10],
    "includeName": true,
    "buttonLabel": "Join the waitlist"
  }
}
```

### Call an endpoint without a typed wrapper

```json
{
  "ability_name": "brevo/api-request",
  "parameters": {
    "method": "GET",
    "endpoint": "smtp/statistics/events?limit=50"
  }
}
```

## Changelog

### 1.0.4
- Added: Generic Brevo v3 API request ability for endpoints not yet wrapped by a typed ability
- Added: Account details ability
- Added: Folder management abilities
- Added: Official Brevo WordPress signup form CRUD and ensure abilities
- Added: Webhook management abilities
- Improved: PATCH and DELETE requests can send JSON bodies when the Brevo endpoint requires them

### 1.0.3
- Added: Sender, sender domain, template, campaign, list, and attribute operations

### 1.0.2
- Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking

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

## Free and Open

Like the rest of the ecosystem, this add-on is free for everyone, fully open source, and designed for real production use.

## Star and Share

If this add-on helps, please star the repo, share the ecosystem, and point people to the main wiki:

- https://github.com/bjornfix/mcp-expose-abilities
- https://github.com/bjornfix/mcp-expose-abilities/wiki

## Links

- [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
- [Main Wiki](https://github.com/bjornfix/mcp-expose-abilities/wiki)
- [Brevo Add-On Guide](https://github.com/bjornfix/mcp-expose-abilities/wiki/Addon-Brevo)
