# FreshRSS Heatmap Extension 🔥

A native extension for [FreshRSS](https://freshrss.org/) that automatically calculates and displays a "temperature" for every article in your feeds, helping you cut through the noise by highlighting articles that are highly upvoted or frequently shared across the web.

## Inspiration
This extension takes heavy inspiration from the legendary **[Fever](https://shauninman.com/archive/2013/12/30/fever_1_18)** RSS reader app created by Shaun Inman. Fever pioneered the concept of tracking links across feeds to calculate a "temperature" for news items, allowing readers to instantly see what the web was talking about. We've ported and modernized those original heat mathematical formulas for FreshRSS!

## How it works

Every article starts at a healthy baseline temperature of **98.6°F**.

When the extension loads an article, it checks three different sources to see if people are talking about it:
1. **Hacker News:** (via Algolia's open API)
2. **Reddit:** (via Reddit's public API)
3. **SearXNG:** (via a local SearXNG instance)

For every mention or upvote, the temperature rises. To prevent a single source (like one subreddit) from artificially inflating the score, the extension uses a **diminishing returns** formula (`Score / 2^n`). This means broad consensus across *different* subreddits or domains creates a much hotter article than one that is simply spammed in a single community.

### UI Indicators
The temperature is injected directly into your feed list and article view:
- 😊 **Normal** (98.6°F)
- 🤒 **Warm** (> 98.6°F)
- 🔥 **Hot** (> 101.0°F)
- 🌋 **Viral** (104.0°F+)
- 🛑 **Frozen** (Article is older than 16 days and no longer auto-updates)

You can also click the floating **🔥 Sort by Heat** button to instantly reorder your current view by temperature, or **click directly on any temperature badge** to force a manual refresh!

## Installation

1. Clone this repository into your FreshRSS `extensions` directory under the name `xExtension-Heatmap`:
   `git clone https://github.com/iovictor/xExtension-Heatmap.git`

2. Log into your FreshRSS account.
3. Go to **Configuration** > **Extensions**.
4. Enable the **Heatmap** extension.
5. *(Optional)* Edit the `credibility_multipliers.json` file to adjust domain weightings for the SearXNG integration.

## Intelligent Caching Schedule

To prevent spamming external APIs and getting rate-limited, the extension uses an intelligent SQLite cache (`heatmap_cache.sqlite`) that dynamically adjusts how often an article's temperature is checked:
- **Phase 1 (Active):** For the first 5 days (if the article is less than 7 days old), the temperature is refreshed every **24 hours**.
- **Phase 2 (Cooling):** After 5 days, it slows down and checks only every **48 hours**.
- **Phase 3 (Frozen):** After 16 days, the extension stops checking automatically (the score is frozen). You can still force an update by clicking the badge.
- **Garbage Collection:** A background process routinely cleans up data older than 60 days to keep the database lean as old posts fall out of your feeds.

## Configuration & Fallbacks

- **SearXNG:** By default, the extension looks for a SearXNG instance at `http://192.168.1.29:8080`. If you don't have one, or if the connection fails, the extension will **gracefully degrade** and simply ignore SearXNG scores without breaking the UI. To change the SearXNG URL, edit `Controllers/heatmapController.php`.
- **API Keys:** No API keys are required out of the box! We rely on public unauthenticated endpoints. If you hit rate limits with Reddit, you may need to implement authenticated requests in the PHP controller.

## Contributing

Feel free to open PRs to add more data sources (Mastodon, GitHub, etc.) or improve the sorting UI!
