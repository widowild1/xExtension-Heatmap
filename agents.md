# Agent Instructions & Conventions

This document provides instructions for any autonomous agents (or subagents) working on this codebase.

## Codebase Context
- **Framework:** FreshRSS (PHP, Minz framework).
- **Extension Path:** `xExtension-Heatmap/`
- **Testing Environment:** Once built, deploy to Raspberry Pi (`192.168.1.29`) at `~/freshrss/data/www/FreshRSS/extensions/` via rsync.
- **Local Services:** SearXNG is available at `http://192.168.1.29:8080/search?q={query}&format=json`.

## Subagent Responsibilities
- **Frontend Agent:** Responsible for `static/heatmap.js` and CSS. Must ensure UI updates are asynchronous and do not block the main FreshRSS thread. Use native DOM methods (no heavy frameworks like React).
- **Backend Agent:** Responsible for `extension.php` and `Controllers/heatmapController.php`. Must implement the SQLite cache and handle API requests to Reddit, HackerNews, and SearXNG.
- **Data Agent:** Responsible for curating the `credibility_multipliers.json` configuration, assigning domain weights based on trustworthiness.

## Development Rules
1. **Never block the page load.** All external API requests must be done asynchronously via the backend controller, triggered by frontend AJAX.
2. **Respect rate limits.** Use the SQLite cache. If a URL is requested, check the cache before hitting Reddit/HN/SearXNG.
3. **Graceful UI.** The UI should default to a "loading" state and silently fail if the backend returns an error.
