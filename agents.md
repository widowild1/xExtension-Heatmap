# Agent Instructions & Conventions

This document provides instructions for any autonomous agents (or subagents) working on this codebase.

## Codebase Context
- **Framework:** FreshRSS (PHP, Minz framework).
- **Extension Path:** `xExtension-Heatmap/`
- **Testing Environment:** Deploy to target test host (`192.0.2.10`) at `/opt/freshrss/extensions/xExtension-Heatmap/` via rsync or Git pull.
- **Local Services:** SearXNG endpoint format `https://searxng.example.com/search?q={query}&format=json`.

## Subagent Responsibilities
- **Frontend Agent:** Responsible for `static/heatmap.js` and `static/heatmap.css`. Must ensure UI updates are asynchronous and do not block the main FreshRSS thread. Use native DOM methods (no heavy frameworks like React).
- **Backend Agent:** Responsible for `extension.php` and `Controllers/heatmapController.php`. Must implement the SQLite cache and handle batched API requests safely.
- **Data Agent:** Responsible for curating the `credibility_multipliers.json` configuration, assigning domain weights based on trustworthiness.

## Development Rules
1. **Never block the page load.** All external API requests must be done asynchronously via the backend controller, triggered by frontend batched AJAX.
2. **Respect rate limits.** Use the SQLite cache. If a URL is requested, check the cache before hitting external APIs.
3. **Protect user privacy.** Never transmit private/intranet URLs or URLs containing authentication tokens to external APIs, and never commit real IPs, domains, or tokens into the repository.
4. **Graceful UI.** The UI should default to a "loading" state and silently fail if the backend returns an error.
