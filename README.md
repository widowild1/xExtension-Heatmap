# FreshRSS Heatmap Extension 🔥

A native extension for [FreshRSS](https://freshrss.org/) that automatically calculates and displays a "temperature" for every article in your feeds, helping you cut through the noise by highlighting articles that are highly upvoted or frequently shared across the web.

## Inspiration
This extension takes heavy inspiration from the legendary **[Fever](https://shauninman.com/archive/2013/12/30/fever_1_18)** RSS reader app created by Shaun Inman. Fever pioneered the concept of tracking links across feeds to calculate a "temperature" for news items, allowing readers to instantly see what the web was talking about. We've ported and modernized those original heat mathematical formulas for FreshRSS!

## How it works

Every article starts at a healthy baseline temperature of **98.6°F**.

When the extension loads an article, it checks multiple sources to see if people are talking about it:
1. **Hacker News:** (via Algolia's open API)
2. **Reddit:** (via Reddit's public API)
3. **SearXNG:** (via a local or private SearXNG instance)
4. **Bluesky, Lobste.rs, Lemmy, Wikipedia, GitHub & Wayback Machine**

For every mention or upvote, the temperature rises. To prevent a single source (like one subreddit) from artificially inflating the score, the extension uses a **diminishing returns** formula (`Score / 2^n`). This means broad consensus across *different* subreddits or domains creates a much hotter article than one that is simply spammed in a single community.

### UI Indicators
The temperature is injected directly into your feed list and article view:
- 😊 **Normal** (98.6°F)
- 🤒 **Warm** (> 98.6°F)
- 🔥 **Hot** (> 101.0°F)
- 🌋 **Viral** (104.0°F+)
- 🛑 **Frozen** (Article is older than 16 days and no longer auto-updates)

You can also click the **🔥 Sort by Heat** button in the top bar to instantly reorder your current view by temperature, or **click directly on any temperature badge** to force a manual refresh!

## Installation
1. `sudo apt update && sudo apt upgrade`
2. `sudo apt install php-sqlite3 php-curl`

3. Clone this repository into your FreshRSS `extensions` directory under the name `xExtension-Heatmap`:
   `git clone https://forgejo.example.com/user/xExtension-Heatmap.git`

4. Log into your FreshRSS account.
5. Go to **Configuration** > **Extensions**.
6. Enable the **Heatmap** extension.
7. *(Optional)* Edit the `credibility_multipliers.json` file to adjust domain weightings for the SearXNG integration.

## Intelligent Caching Schedule

To prevent spamming external APIs and getting rate-limited, the extension uses an intelligent SQLite cache (`heatmap_cache.sqlite`) that dynamically adjusts how often an article's temperature is checked:
- **Phase 1 (Active):** For the first 5 days (if the article is less than 7 days old), the temperature is refreshed every **24 hours**.
- **Phase 2 (Cooling):** After 5 days, it slows down and checks only every **48 hours**.
- **Phase 3 (Frozen):** After 16 days, the extension stops checking automatically (the score is frozen). You can still force an update by clicking the badge.
- **Garbage Collection:** A background process routinely cleans up data older than 60 days to keep the database lean as old posts fall out of your feeds.

## Configuration & Fallbacks

- **SearXNG:** Configure your SearXNG URL (e.g., `https://searxng.example.com`) directly in **Configuration > Extensions > Heatmap**. If you don't have one, or if the connection fails, the extension will **gracefully degrade** and ignore SearXNG scores without breaking the UI.
- **Privacy Guard:** Intranet URLs (`.local`, `.lan`, private IP ranges) and URLs containing query authentication tokens are automatically excluded from external API queries and caching.
- **API Keys:** No API keys are required out of the box. Optional tokens for GitHub and Hister can be configured in the extension settings.

## Contributing

Feel free to open PRs to add more data sources or improve the sorting UI!
