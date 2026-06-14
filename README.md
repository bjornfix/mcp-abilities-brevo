# MCP Abilities - Brevo

Brevo (Sendinblue) abilities for MCP. Manage contacts, lists, WonderPush localization, and send emails via Brevo API.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-brevo)](https://github.com/bjornfix/mcp-abilities-brevo/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 1.0.7
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Brevo (Sendinblue) abilities for MCP. Manage contacts, lists, WonderPush localization, and send emails via Brevo API.

This plugin is part of the Devenia MCP abilities ecosystem. It gives an MCP-capable agent a focused, authenticated way to work with Brevo work inside WordPress through MCP.

**Example:** "Handle this WordPress maintenance task directly." - The agent can inspect the site, call the relevant ability, and return the result without making the human click through wp-admin for every step.

## The Real Workflow

In practice, the human should not have to memorize every ability name.

The normal pattern is:

1. install the base MCP stack
2. install only the add-ons the site actually needs
3. let the agent discover the available abilities
4. give the agent a clear task with boundaries
5. verify the result in WordPress

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress automation still leaves the repetitive part to the human.

This plugin is different because the agent can act inside the site through a narrow, authenticated ability surface:

- inspect current site state before changing anything
- run the specific action needed for the task
- return structured results that are easy to verify
- keep the workflow inside WordPress instead of a separate checklist

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- postpone maintenance because the task is tedious

### After

- tell the agent what needs doing
- let it inspect the relevant WordPress state
- let it run the targeted ability
- verify the result and move on

## Who It Is For

This is a good fit for:

- agencies managing WordPress sites with AI-assisted maintenance
- operators who want agents to do real WordPress work instead of producing instructions
- teams already using MCP Expose Abilities
- sites where this WordPress area is updated often enough to deserve automation

It is especially useful when the manual version is repetitive enough that important maintenance gets delayed.

## Documentation

Start with the main plugin page and base stack documentation:

- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Abilities API**.
2. Install **MCP Adapter**.
3. Install **MCP Expose Abilities**.
4. Install **MCP Abilities - Brevo**.
5. Confirm the new abilities appear in discovery.
6. Give the agent a clear task that uses this add-on.

If you skip base-stack verification and start with add-ons immediately, troubleshooting gets harder than it needs to be.

## Abilities (48)

| Ability | Description |
|---------|-------------|
| `brevo/api-request` | Call any Brevo v3 endpoint with the configured API key |
| `brevo/get-account` | Get Brevo account and plan details |
| `brevo/wonderpush-get-localization` | Read stored WonderPush prompt/widget localization |
| `brevo/wonderpush-update-localization` | Create or update runtime WonderPush localization for one language |
| `brevo/wonderpush-audit-localization` | Audit WonderPush localization coverage against known site languages |
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
| `brevo/list-language-audiences` | Audit Brevo language attribute and language-specific lists |
| `brevo/ensure-language-audiences` | Create missing Brevo language attribute and language-specific lists |
| `brevo/upsert-language-contact` | Create or update a contact with language attribute and matching list |
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

### Ensure language-specific audiences

```json
{
  "ability_name": "brevo/ensure-language-audiences",
  "parameters": {
    "folderId": 12,
    "listPrefix": "Devenia",
    "attributeName": "LANGUAGE"
  }
}
```

### Upsert a contact into the matching language list

```json
{
  "ability_name": "brevo/upsert-language-contact",
  "parameters": {
    "email": "user@example.com",
    "language": "nb",
    "listPrefix": "Devenia"
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

### Localize WonderPush subscription UI

```json
{
  "ability_name": "brevo/wonderpush-update-localization",
  "parameters": {
    "language": "nb",
    "locale": "nb-NO",
    "texts": {
      "subscriptionBell": {
        "dialogTitle": "Administrer varsler",
        "subscribeButtonTitle": "Abonner",
        "advancedSettingsDescription": "Dine personlige varslingsdata:"
      },
      "subscriptionDialog": {
        "positiveButton": "Abonner",
        "negativeButton": "Senere"
      }
    }
  }
}
```

## Changelog

### 1.0.7
- Fixed: Language-audience list lookup now respects Brevo contact-list pagination limits

### 1.0.6
- Added: Language-audience audit for Brevo contact language attributes and per-language lists
- Added: Ability to ensure language-specific Brevo audiences from the site language registry
- Added: Contact upsert ability that stores the normalized language attribute and adds the contact to the matching language list

### 1.0.5
- Added: Runtime WonderPush localization abilities for prompt/widget text stored in WordPress options
- Added: Frontend WonderPush init-option merge for localized subscription bell, dialog, switch, and opt-in text
- Added: WonderPush localization audit against known site languages

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

## Contributing

PRs welcome. Keep changes focused on the plugin's WordPress ability surface and preserve authenticated, explicit workflows.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [GitHub Releases](https://github.com/bjornfix/mcp-abilities-brevo/releases)

## Star and Share

If this plugin saves you time or makes WordPress maintenance easier to verify, please:

- star the repo
- share it with people running WordPress sites
- point them to the main plugin page so they can see what the ecosystem can actually do

Why do it?

Because agent-friendly open WordPress tooling helps more of the boring but important work get done.
