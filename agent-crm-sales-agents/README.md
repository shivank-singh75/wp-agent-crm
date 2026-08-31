# Agent CRM Sales Agents

Uploadable WordPress plugin for displaying sales agents from an Agent CRM backend instance.

## What It Calls

By default the plugin calls:

```text
POST {CRM_BASE_URL}/api/v1/websites/sales-agents/listing
```

The CRM backend at `/home/Essence/2026/agent-crm/agent-crm-backend` protects this endpoint with `requireWebsiteBasicAuth`, using the Website Client ID and Website Client Secret as Basic Auth credentials:

```text
x-tenant-id: {Instance / Tenant ID}
Authorization: Basic base64(clientId:clientSecret)
Content-Type: application/json
```

Existing installs with the old `/api/v1/agent` endpoint saved in settings are automatically moved to this website listing endpoint.

The plugin posts JSON similar to:

```json
{
  "tenantId": 1,
  "pageSize": 100,
  "pageNumber": 1
}
```

## Install

1. Zip the `agent-crm-sales-agents` folder.
2. In WordPress admin, go to Plugins > Add New > Upload Plugin.
3. Upload the zip and activate it.
4. Go to Settings > Agent CRM Agents.
5. Enter the CRM base URL, website client credentials, tenant ID, and display options.
6. Use the Test Connection button.

## Shortcode

```text
[agent_crm_sales_agents]
```

Optional attributes:

```text
[agent_crm_sales_agents layout="list" title="Available Agents"]
```

Supported attributes:

- `layout`: `grid` or `list`.
- `title`: Overrides the configured heading.
- `show_email`: `1` or `0`.
- `show_phone`: `1` or `0`.
- `show_distance`: `1` or `0`.

## REST Proxy

The plugin also exposes a public WordPress REST proxy:

```text
GET /wp-json/agent-crm-sales-agents/v1/agents
```

This endpoint returns normalized WordPress JSON while keeping CRM website client credentials server-side.

## Configurable Settings

- CRM Base URL
- Agent Endpoint Path
- Instance / Tenant ID
- Website Client ID
- Website Client Secret
- Title
- Layout
- Email, phone, and distance visibility
- Cache TTL
- Empty message

## Packaging Locally

From the repository root:

```bash
cd wordpress-plugins
zip -r agent-crm-sales-agents.zip agent-crm-sales-agents
```

The generated zip is the file to upload to WordPress.
