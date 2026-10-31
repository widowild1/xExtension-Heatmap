# Project Goal: FreshRSS Heatmap Extension

## Primary Objective
Develop a native FreshRSS extension (`xExtension-Heatmap`) that calculates a "heat" and "credibility" score for RSS articles. This score will be visually represented in the UI (both in the post list and the post reading view) using emojis ranging from cold to hot.

## Core Features
1. **Asynchronous Scoring:** 
   - Inject JavaScript into the frontend via FreshRSS hooks (`entry_before_display`).
   - JS calls a PHP backend route to fetch scores lazily, preventing RSS feed loading delays.
2. **Multi-Source Credibility Scoring:**
   - **Hacker News API:** Points for upvotes and comments on tech/startup topics.
   - **Reddit API:** Points for subreddit cross-posts and upvotes.
   - **SearXNG API:** Query local SearXNG (`192.168.1.29:8080`) to find web mentions, adjusting score based on domain credibility.
3. **Dynamic UI:**
   - Visual indicators in both the feed list view and article view.
   - Emojis based on score thresholds (e.g., ❄️ Cold, 😐 Neutral, 🔥 Hot, 🌋 Viral).
4. **Caching:**
   - Store calculated scores in a SQLite table to prevent API rate limiting and improve performance.

## Success Criteria
- The extension installs seamlessly in FreshRSS.
- The UI gracefully degrades if an API fails.
- The database cache prevents duplicate external API calls within a 24-hour window.
