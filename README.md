# ICS Filter REST API for WordPress

A robust, secure, and high-performance WordPress plugin that adds a custom REST API endpoint to fetch, parse, filter, and output `.ics` calendar files based on keyword matching within event summary lines.

---

## Features

- **Custom REST API Endpoint:** Exposes a clean endpoint at `/wp-json/icsfilter/v1/filter`.
- **Flexible Keyword Filtering:** Accepts multiple comma-separated keywords (supports spaces within phrases, e.g., `"Team Meeting"`). Matches events where **all** specified keywords appear in the summary line (case-insensitive).
- **Raw Calendar Streaming:** Outputs direct `text/calendar` file streams with proper line breaks, compatible with all calendar clients (Apple Calendar, Google Calendar, Outlook).
- **Secure API Key Authentication:** Protects your endpoint from abuse. Keys can be passed via HTTP header (`X-API-Key`) or query parameter (`?api_key=`).
- **Built-in Transient Caching:** Automatically caches filtered calendar outputs in the WordPress database for 1 hour to optimize performance and prevent hammering external calendar servers.
- **Settings & Cache Management Dashboard:** Easily manage your secret API key and clear cached calendar data on demand from **Settings > ICS Filter API**.

---

## Installation

1. Download the plugin code [ics-filter-wordpress-api-plugin.zip](https://github.com/w0yl/ics-filter-wordpress-api-plugin/releases/latest/download/ics-filter-wordpress-api-plugin.zip) from the [latest release](https://github.com/w0yl/ics-filter-wordpress-api-plugin/releases/latest).
2. Log in to your WordPress Dashboard and navigate to **Plugins --> Add Plugins --> Upload Plugin** and choose the downloaded plugin.
3. Activate **ICS Filter REST API Plugin**.

---

## Configuration & Cache Management

1. Go to **Settings > ICS Filter API** in your WordPress dashboard sidebar.
2. **API Key:** Set your custom secure API key (a default key is provided upon activation, but you should change it for security).
3. **Clearing Cache:** If your source calendar changes and you need to force an immediate refresh, click the **Clear Calendar Cache** button at the bottom of the settings page to purge all active transients.

---

## API Usage

### Endpoint URL
***
GET /wp-json/icsfilter/v1/filter
***

### Parameters

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `url` | String (URL) | **Yes** | The absolute, URL-encoded web address of the target `.ics` calendar file. |
| `keywords` | String | **Yes** | Comma-separated list of keywords to filter by. Spaces within words/phrases are supported. |
| `api_key` | String | **No** | Your secret API key (can also be passed via header). |

### Authentication Header (Alternative)
Instead of passing the key as a query parameter, you can send it securely via the request header:
***
X-API-Key: your_secret_api_key_here
***

---

## Examples

### Example Request (Using Query Parameters for Authentication)
***
curl -X GET "https://example.com/wp-json/icsfilter/v1/filter?url=https%3A%2F%2Fcalendar.google.com%2Fcalendar%2Fical%2Fen.usa%2523holiday%40group.v.calendar.google.com%2Fpublic%2Fbasic.ics&keywords=New%20Year,Day&api_key=your_secret_key"
***

### Example Request (Using HTTP Header for Authentication)
***
curl -X GET "https://example.com/wp-json/icsfilter/v1/filter?url=https%3A%2F%2Fexample.com%2Fcalendar.ics&keywords=Project,Meeting" \
     -H "X-API-Key: your_secret_key"
***

---

## Caching Behavior
Responses are cached using WordPress Transients (`get_transient` / `set_transient`). The cache key is generated dynamically from a hash of the target calendar URL and your sorted keywords, expiring automatically after **1 hour**. You can manually flush this cache at any time via the plugin settings page.

---

## License
The code has been Licensed under GNU GPLv2.
