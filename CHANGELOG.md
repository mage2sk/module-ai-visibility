# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-10-30

### Added
- Recommendations page with ten rules that turn the collected data into concrete fixes: `robots.txt` blocking AI search bots, pages answering client errors to AI bots (with redirect suggestions), server errors or slow pages, products AI crawlers never visited, AI answers that send shoppers but no sales, thin product content read by AI, `llms.txt` not served, AI crawl wasted on URLs with parameters, AI shoppers landing on out-of-stock products, and unknown AI bots waiting for review. Each finding shows its evidence, a "How to fix" text and links, and can be applied with a note, dismissed, rated or undone.
- Fix verification: after the verification window the module compares the days before and after an applied fix and reports "likely helped", "no clear effect", "likely made it worse" or "not enough data yet" with a 95% range. Changed pages and products are compared with similar unchanged ones, matched on both the "before" window and the window of the same length before it (only pages within close range of each other in both windows count as comparable; difference in differences with a bootstrap range and a cross-check against a second baseline that must agree; "not enough data yet" when no comparable pages exist), whole-store counts with a Poisson rate test, the average response time with a t-test on daily means, and never-fetched products as shares in percentage points. Page and product fixes report "not enough data yet" until that comparison history (two verification windows before the change) exists.
- "Top recommendations" and "Latest verdicts" cards on the dashboard, below the KPIs.
- Weekly summary email (off by default) with crawler hits, AI visits, orders and revenue against the previous week, top pages and sources, open recommendations and new verdicts; sent once per ISO week at the configured weekday and hour (UTC), with catch-up of a missed run.
- CSV exports on the dashboard for the daily series, top pages, bots, AI sources, recommendations and verdicts.
- ACL resource `Panth_AiVisibility::recommendations` for the Recommendations page, the two dashboard cards and the recommendations / verdicts exports.
- Console commands `panth:aivisibility:recommend`, `panth:aivisibility:verify` and `panth:aivisibility:email`, with `--simulated` previews of simulator data that are never saved or sent, and cron jobs for the nightly recommendations, the verification and the weekly email.
- Uncapped daily counts of AI crawler hits per product and category, and of AI visits per landing path, plus per-path error and response-time counts, so fixes can still be verified after the raw rows are deleted.
- Simulator scenarios that plant an applied fix with or without an effect, for testing the verification.

### Changed
- License: AI Visibility is free and open-source software under the MIT License (since 1.0.1).
- `setup:upgrade` from 1.0 rebuilds the daily summaries of the days still inside the raw retention (at most 60 days), so those days get the new per-product and per-path detail at once; older days keep their 1.0 summaries. A day that cannot be rebuilt is logged and skipped.
- Top pages keep, beyond the 500 most fetched paths, paths with at least 3 errors or 3 slow answers that day, at most 2000 per store view and day.
- The daily crawler summary also counts timed hits and hits with and without a query string (empty on days summarised before 1.1).
- Retention also covers the new daily tables and never removes the weekly email marker.
- New index on status and time in `panth_aiv_hit`: the rules for client errors and for server errors or slow pages read only the days they check, so they stay fast with millions of kept crawler hits.
