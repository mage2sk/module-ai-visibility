# Magento 2 AI Visibility

Panth AI Visibility shows how AI crawlers and AI assistants use a Magento 2 store. It counts requests from AI crawlers (training, search and live-answer bots), including pages served from Magento's built-in full page cache, detects shoppers who arrive from AI assistants such as ChatGPT, Perplexity, Gemini or Copilot, credits their orders to the assistant, and presents the results on an admin dashboard with insights and data-health checks.

Version 1.1 turns the numbers into a to-do list: every night ten rules check the collected data and list concrete fixes ranked by how much AI traffic they affect (for example pages that answer 404 to AI bots, or products no AI bot has fetched). When you mark a fix as applied, the module compares the traffic before and after the change with similar pages or products you did not change and reports whether the fix likely helped, always with a range. A weekly summary email and CSV exports of every report complete the release.

Everything is measured and stored inside the Magento database. The module makes no outbound requests and sends no data to third-party services.

## Features

- **AI crawler tracking.** 22 built-in AI bots from OpenAI, Anthropic, Perplexity, Meta, Amazon, Apple, DuckDuckGo, Mistral, You.com, ByteDance, Common Crawl, Cohere and Diffbot (for example GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Amazonbot, Applebot, Bytespider, CCBot). Each hit is stored with bot, company, category, store view, page type (product, category, CMS, other), path, HTTP status and response time.
- **Cached pages are counted.** Hits are recorded while Magento serves the response, including built-in full page cache hits. Human visitors only cost an in-memory User-Agent check and cause no database work.
- **Bot categories.** Every bot is classed as training (collects data for model training), search (builds an AI search index) or live answer (fetches a page while a user is waiting for an answer), so the dashboard shows which kind of AI traffic a page receives.
- **AI referral visits.** A small CSP-safe collector script (under 2 KB, no libraries, works on Hyva and Luma) reports once per browser session when a shopper arrives from an AI assistant. Built-in sources: ChatGPT, Perplexity, Gemini, Microsoft Copilot, Claude, Meta AI, Le Chat (Mistral), DeepSeek, Grok and You.com, recognised by referrer host or `utm_source`. Custom hosts and `utm_source` values can be added.
- **Signed attribution cookie.** The first and the latest AI source are kept in a signed, HttpOnly, SameSite=Lax first-party cookie (`panth_aiv`) for a configurable attribution window. Magento cookie consent is respected.
- **Order attribution.** Orders placed through the one-page checkout and the multishipping checkout are tagged with the first-touch and the last-touch AI source. An "AI Source" column and filter appear in the order grid, and an "AI Attribution" block on the order view. The attribution model (first or last touch) can be switched at any time without losing data.
- **Dashboard and insights.** KPIs, server-rendered SVG charts, top pages, top bots, AI visits and revenue by source, with a date range and store view filter. Insights point out AI bots blocked by `robots.txt`, `llms.txt` fetches, products that AI bots have not fetched and bot error rates.
- **Crawler Log.** Admin grid of detailed crawler hits with filters, column controls and export.
- **Discovery.** Bot-like User-Agents that are not in the catalogue, clients that fetch `/llms.txt`, and AI-looking referrer hosts and `utm_source` values are listed as candidates. Confirm a candidate as AI (it is then counted like a built-in bot or source, including its earlier hits), mark it Not AI, or ignore it. Ordinary browsers and well-known search engine, social, SEO and uptime bots are never listed.
- **Data-health checks.** Seven checks (page cache, cron, collector, cookie consent, table size, access log import, tracking) run hourly and on demand, and are shown on the dashboard.
- **Access log importer for Varnish and CDN stores.** Cached responses served by Varnish or a CDN never reach Magento. The importer reads nginx, Apache and varnishncsa access logs (combined format) with resume, rotation detection and locking, so these hits are counted as well.
- **Traffic simulator.** A deterministic generator creates realistic test traffic (marked as simulated and hidden on the dashboard by default) and removes it again with one option.
- **Bounded storage.** Raw rows are kept for a configurable number of days, daily summaries for a configurable number of months, and a daily cap limits detailed rows during a crawler storm while totals stay correct.
- **Recommendations (1.1).** Ten rules run nightly per store view and list concrete fixes on **Panth Infotech > AI Visibility > Recommendations**, ranked by an impact score of 0 to 100, each with its evidence (numbers, date range, top examples, bots involved), a "How to fix" text and a link to the admin page where the fix is made. Each recommendation can be marked as applied (with an optional note), dismissed for 30 days, or rated useful / not useful; "not useful" lowers the ranking of that rule. The rules only read data; nothing in the store is changed automatically.
- **Fix verification (1.1).** An applied fix is checked after the verification window (default 14 days before vs 14 days after, UTC days). The result reads "Likely helped", "No clear effect", "Likely made it worse" or "Not enough data yet", always with a 95% range and a before / after bar chart. "Likely helped" means the whole range points to an improvement. A fix can be undone while it is still waiting for its verdict.
- **Weekly summary email (1.1).** Once a week cron emails AI crawler hits, live-answer fetches, AI visits, orders and revenue compared with the week before, the top pages and AI sources, the top open recommendations, verdicts reached that week and new Discovery candidates. Plain HTML, no images, no tracking.
- **CSV export (1.1).** The dashboard exports the daily series, top pages, bots, AI sources, recommendations and verdicts as CSV with the dashboard's date range and store view filter. Cells that could start a spreadsheet formula are neutralised.

## Compatibility

| Platform | Versions |
|---|---|
| Magento Open Source | 2.4.7 to 2.4.9 |
| Adobe Commerce | 2.4.7 to 2.4.9 |
| PHP | 8.1, 8.2, 8.3, 8.4, 8.5 |
| Themes | Hyva and Luma |

## Requirements

- Magento Open Source or Adobe Commerce 2.4.7 to 2.4.9.
- PHP 8.1 to 8.5.
- `mage2kishan/module-core` (module `Panth_Core`), installed automatically by Composer.
- MySQL 8 or MariaDB 10.6 or later (all module tables use `utf8mb4`).
- A working Magento cron for the daily summaries, retention, Discovery, health checks and the access log import.

## Installation

```bash
composer require mage2kishan/module-ai-visibility
bin/magento module:enable Panth_Core Panth_AiVisibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

- `setup:di:compile` and `setup:static-content:deploy` are only needed in production mode.
- `setup:upgrade` creates the 19 `panth_aiv_*` tables.

Check that the module is enabled:

```bash
bin/magento module:status Panth_AiVisibility
```

## Configuration

Settings: **Stores > Configuration > Panth Infotech > AI Visibility** (section `panth_aivisibility`, ACL resource `Panth_AiVisibility::config`).

Reports: **Panth Infotech > AI Visibility** in the admin menu, with the pages Dashboard, Recommendations, Crawler Log, Discovery and Configuration.

| ACL resource | Allows |
|---|---|
| `Panth_AiVisibility::dashboard` | Dashboard and the traffic CSV exports (daily series, top pages, bots, AI sources) |
| `Panth_AiVisibility::recommendations` | Recommendations page (apply, dismiss, feedback, undo, check now), the "Top recommendations" and "Latest verdicts" dashboard cards and the recommendations / verdicts CSV exports (they contain admin user names and notes) |
| `Panth_AiVisibility::log` | Crawler Log |
| `Panth_AiVisibility::discovery` | Discovery |
| `Panth_AiVisibility::config` | Configuration section |

Defaults after installation: crawler tracking, visit tracking, Discovery and recommendations are on; the access log import is off until log files are listed, and the weekly summary email is off until it is switched on with at least one recipient.

| Group | Setting | Default |
|---|---|---|
| General | Enable AI Visibility (store view scope) | Yes |
| General | Track AI Crawlers | Yes |
| General | Track Visits from AI Assistants | Yes |
| General | Attribution Window (days), 1 to 365 | 30 |
| General | Attribution Model | First touch |
| AI Crawlers | Discover Unknown AI Bots and Apps | Yes |
| AI Crawlers | Custom AI Bots (`Name \| token \| category \| company` per line) | empty |
| AI Crawlers | Max Detailed Hits per Day, 1000 to 10000000 | 200000 |
| AI Referrer Sources | Custom AI Referrer Hosts (`host \| Source Name` per line) | empty |
| AI Referrer Sources | Custom utm_source Values (`utm_value \| Source Name` per line) | empty |
| Storefront Collector | Max Reports per IP per Minute, 1 to 600 | 20 |
| Storefront Collector | Direct Landing Sample Rate (%), 0 to 100 | 25 |
| Privacy and Data Retention | Respect Cookie Consent | Yes |
| Privacy and Data Retention | Keep Detailed Crawler Hits (days), 1 to 365 | 14 |
| Privacy and Data Retention | Keep Detailed AI Visits (days), 1 to 730 | 30 |
| Privacy and Data Retention | Keep Direct Landings (days), 1 to 365 | 30 |
| Privacy and Data Retention | Keep Daily Summaries (months), 1 to 60 | 13 |
| Access Log Import (Varnish / CDN) | Import Crawler Hits from Access Logs | No |
| Access Log Import (Varnish / CDN) | Log Files (`/absolute/path/access.log \| store_code` per line) | empty |
| Access Log Import (Varnish / CDN) | Max Lines per Run, 1000 to 5000000 | 200000 |
| Recommendations | Create Recommendations | Yes |
| Recommendations | Minimum Events per Finding, 1 to 1000000 | 20 |
| Recommendations | Max Open Recommendations per Store View, 1 to 500 | 50 |
| Fix Verification | Verification Window (days), 7 to 56 | 14 |
| Fix Verification | Minimum Events for a Verdict, 1 to 1000000 | 30 |
| Fix Verification | Max Comparison Subjects, 3 to 500 | 50 |
| Weekly Summary Email | Send Weekly Summary | No |
| Weekly Summary Email | Recipients (comma or line separated, up to 20) | empty |
| Weekly Summary Email | Email Sender (store email identity) | General Contact |
| Weekly Summary Email | Send on (weekday, UTC) | Monday |
| Weekly Summary Email | Send at (UTC) | 07:00 |

Numeric settings are checked when they are used: values out of range fall back to the nearest allowed value or the default.

## Console commands

| Command | Purpose |
| --- | --- |
| `bin/magento panth:aivisibility:rollup [--date=Y-m-d] [--days=N]` | Rebuild the daily summaries for a day (default today) or the last N days. Safe to run any time. |
| `bin/magento panth:aivisibility:import-logs [--file=/abs/path] [--dry-run] [--from-start --force]` | Import AI crawler hits from the configured access logs now. `--dry-run` parses and counts without writing (also while the import is disabled). `--from-start` reads the files again from byte 0 and needs `--force`, because lines imported before are counted twice. |
| `bin/magento panth:aivisibility:simulate --days=30 [--seed=7] [--store=1] [--scale=1] [--until=Y-m-d] [--with-orders=N] [--force] [--purge]` | Generate deterministic simulated traffic for testing (marked `is_simulated`, hidden on the dashboard unless "show simulated data" is used). `--purge` removes every simulated row. Do not run it on a production store. |
| `bin/magento panth:aivisibility:recommend [--store=ID] [--dry-run] [--simulated]` | Evaluate the recommendation rules now and update the recommendation list. `--dry-run` prints the findings without saving them. `--simulated` also reads simulator rows (a test preview; it implies `--dry-run`, so simulated findings are never saved). |
| `bin/magento panth:aivisibility:verify [--action=ID] [--dry-run]` | Verify applied fixes whose verification window has ended, or one action now. `--dry-run` prints the result without saving it. |
| `bin/magento panth:aivisibility:email [--dry-run [--simulated]] [--to=address]` | Send the weekly summary email now, or print it with `--dry-run`. `--to=address` sends a test copy to that address only (also while the summary is switched off) and does not count as the weekly send. `--dry-run --simulated` includes simulator rows in the preview; simulated numbers are never sent. |

## Cron jobs (group `default`)

| Job | Schedule | What it does |
| --- | --- | --- |
| `panth_aiv_rollup` | every 30 minutes | Rebuilds the daily summaries of today and yesterday. |
| `panth_aiv_retention` | 03:10 daily | Deletes raw and daily rows past the retention settings, expired rate-limit rows, and Discovery candidates that are not confirmed and were not seen for 90 days. |
| `panth_aiv_discovery` | hourly at :25 | Scores AI referrer candidates against live-answer crawler fetches. |
| `panth_aiv_health` | hourly at :40 | Runs the data-health checks shown on the dashboard. |
| `panth_aiv_import_logs` | every 5 minutes | Imports new access log lines (does nothing unless the log import is enabled). |
| `panth_aiv_recommend` | 04:20 daily | Evaluates the recommendation rules (real data only). |
| `panth_aiv_verify` | 04:40 daily | Verifies applied fixes whose verification window has ended. |
| `panth_aiv_weekly_email` | hourly at :05 | Sends the weekly summary email at the configured weekday and hour (UTC), once per ISO week; a run missed at that hour is caught up later in the same week. |

## How the numbers are kept

- Raw rows (crawler hits, AI visits, landings) are kept for the configured number of days; daily summaries are kept for the configured number of months, so the dashboard keeps its history after raw rows are deleted.
- All days are UTC days and every stored time is UTC, whatever the store or server time zone.
- The daily summaries are rebuilt from the raw rows per store view and per real / simulated data. A day whose raw rows were already removed by retention is never rebuilt, so its summaries are kept as they are. Raising a retention setting does not bring deleted raw rows back.
- Above "Max Detailed Hits per Day" a hit is only counted (per bot, store view and status class), not stored in detail; it still appears in the totals as page type "other". Unknown bots above the cap share one counter.
- Top pages keep the 500 most fetched paths per store view, category and day. Beyond that limit a path is still kept when it had at least 3 client or server errors (4xx / 5xx) that day, or at least 3 timed hits that took more than 2 seconds on average; at most 2000 such extra paths are kept per store view and day (most errors first), so error and speed fixes can be verified after the raw rows are deleted while a flood of one-off 404s cannot grow the table without limit.
- Since 1.1 a separate daily table counts AI crawler hits per product and per category without any limit (`panth_aiv_entity_daily`). The "products AI crawlers never visited" recommendation and the verification of product and category fixes read it, so large catalogues are covered completely.
- Since 1.1 AI visits are also counted per day and landing path without any limit (`panth_aiv_visit_path_daily`), so visit and order fixes on single pages can be verified after the raw visit rows are deleted.
- Since 1.1 the daily crawler summary also counts the hits with a measured response time and the hits with and without a query string (columns `timed_hits`, `query_hits`, `canonical_hits` of `panth_aiv_crawl_daily`). On days summarised before 1.1 these columns are empty (NULL); the verification leaves those days out of the average response time and the share of clean URLs instead of counting their hits as untimed or as clean.
- The table size health check uses MySQL table statistics, which the database refreshes on its own; the figure is an estimate.

## Recommendations and fix verification

The recommendation rules run every night (cron `panth_aiv_recommend`) and on **Check this store view now** on the Recommendations page. A problem is only listed when at least "Minimum Events per Finding" requests, visits or items are behind it, and at most "Max Open Recommendations per Store View" are kept, best first.

| Rule | Looks at |
|---|---|
| robots.txt blocks AI search bots | AI search and live-answer bots that `robots.txt` blocks (needs the Panth robots.txt module) |
| Pages answer client errors (4xx) to AI bots | Client errors AI bots received in the last 7 complete days, with redirect suggestions |
| Server errors or slow pages for AI bots | 5xx answers, or pages that took over 2 seconds on average, in the last 7 complete days |
| Products AI crawlers never visited | Enabled, visible, in-stock products no AI crawler fetched in 90 days (needs 14 days of per-product history) |
| AI answers send shoppers but no sales | Pages fetched by live-answer bots that sent AI visitors but no orders in 30 days |
| Thin product content read by AI | Products read by AI bots with a description under 150 words or images without a label |
| llms.txt is not served | `llms.txt` switched off, answering with errors, or never fetched while AI bots crawl the store (30 days) |
| AI crawl wasted on URLs with parameters | More than 30% of AI crawler hits on URLs with query strings in the last 7 complete days |
| AI shoppers land on out-of-stock products | AI visitors landing on out-of-stock products in 30 days |
| Unknown AI bots and apps wait for review | Discovery candidates waiting for review for more than 7 days |

Mark a fix as applied after you made the change. After "Verification Window (days)" the module compares the days before the change with the same number of days after it (the day of the change is left out), using the measure that fits the rule:

- Pages or products you changed are compared with similar ones you did not change (difference in differences with a bootstrap range), so a store-wide trend or a new bot is not mistaken for the effect of the fix. Each changed page is paired only with unchanged pages that had similar numbers (within about 1.65 times) in both the "before" window and the window of the same length before it (the comparison window). The rules pick pages because they were unusually high just before the change (for example the pages with the most 404s in the last 7 days), and such pages fall back towards their usual level afterwards even when nothing changed; partners that were just as high in both windows fall back the same way. Pages without at least 3 such partners, for example pages that only started erroring a few weeks ago while no other page did, get "Not enough data yet" with the reason "no comparable subjects" instead of a guess. A second comparison must agree: the comparison window as the baseline, with partners picked on the "before" window (right when pages were picked for a reason the numbers do not show, such as best sellers). The range shown covers both ranges. When the changed pages had nothing in the comparison window the second comparison cannot be computed, and the first one decides alone; that is only possible with partners that also had (almost) nothing then. Without data for the comparison window (a new store, or right after the upgrade) the result is "Not enough data yet" with the reason "comparison history not available yet"; the module never falls back to a weaker comparison.
- Whole-store counts (for example `llms.txt` fetches) are compared before and after with a Poisson rate test; the average response time with a t-test across the daily averages.
- Products that were never fetched are compared as shares: the share of fixed products that got crawled after the change against the share of other products, not fetched in the 90 days before, that got their first crawl normally, with the difference in percentage points. A share below the normal one is reported as "no clear effect", never as "worse".

Limitation: the comparison assumes the changed pages would have moved like their unchanged partners. Pages that were already rising or falling on their own before the change (for example a product whose demand was growing) can be misjudged, because that trend looks like the effect of the fix; read a "likely helped" on such pages with care. With fewer than "Minimum Events for a Verdict" events the result is "Not enough data yet". The Recommendations page, the dashboard cards, the weekly email and the cron jobs use real data only; simulated rows only appear in the `--simulated` command line previews.

## Upgrading from 1.0

1. Run `composer update mage2kishan/module-ai-visibility`, then `bin/magento setup:upgrade` (plus `setup:di:compile` and `setup:static-content:deploy` in production mode) and `bin/magento cache:flush`.
2. `setup:upgrade` adds the three 1.1 tables (`panth_aiv_recommendation`, `panth_aiv_action`, `panth_aiv_impact`), the per-product table `panth_aiv_entity_daily`, the per-landing-path visit table `panth_aiv_visit_path_daily` and new columns in the daily summaries (19 tables in total), plus an index on status and time in `panth_aiv_hit` for the crawler error rules (on a table with millions of crawler hits this adds some time to the upgrade). Existing data is kept; the new crawler summary columns stay empty (NULL) on days summarised before 1.1.
3. During `setup:upgrade` a one-time data patch rebuilds the daily summaries of every day still inside the raw retention, that is the larger of "Keep Detailed Crawler Hits (days)" (default 14) and "Keep Detailed AI Visits (days)" (default 30), at most 60 days, oldest day first. Those days get per-product counts, per-path visit counts and error / speed details at once. A day that cannot be rebuilt is skipped and logged as a warning in `var/log/system.log`; the upgrade itself never stops. To rebuild later, run `bin/magento panth:aivisibility:rollup --days=30` (use your retention setting).
4. Days older than the raw retention keep their 1.0 summaries: dashboard totals, charts and top pages stay as they were, but those days have no per-product counts and no per-page error details.
5. What to expect: the first recommendations appear after the next nightly run (or click **Check all store views now**). "Products AI crawlers never visited" waits until 14 days of per-product history exist. The verification of a fix on pages or products needs two full windows of per-day history before the change (the comparison window and the "before" window, 2 x "Verification Window (days)", 28 days by default); until then it reports "Not enough data yet" with the reason "comparison history not available yet". The upgrade rebuilds per-product and per-page crawler counts only for the days whose detailed crawler hits are still kept ("Keep Detailed Crawler Hits (days)", default 14), so with the defaults crawler fixes applied in the first 14 days after the upgrade get that answer (28 - 14 days; none with a retention of 28 days or more).
6. Give the new ACL resource `Panth_AiVisibility::recommendations` to the admin roles that should see the Recommendations page; roles with only the Dashboard resource no longer see the recommendations cards or download the recommendations and verdicts exports.
7. The weekly summary email stays off until you switch it on and enter recipients.

## Access log import (Varnish / CDN)

Behind Varnish or a page-caching CDN most bot requests never reach PHP. Enable **Access Log Import**, then list one file per line as `/absolute/path/access.log | store_code`. The combined / varnishncsa default format is read.

- Only new, complete lines are read; the position is saved per file. Rotation (new inode), truncation (`> file`) and a file that was truncated and written again past the saved position are detected and read from the start.
- Configure the current log file only. Compressed rotated files (`.gz`) are not supported and are reported as an error. A symlink and its target are imported once.
- Web API requests (`/rest`, `/soap`, `/graphql`), static files and media (except sitemaps) are skipped, like for requests handled by PHP.
- Days that receive hits are summarised at the end of each run, also days older than yesterday. Lines of days whose raw rows were already removed by retention are only summarised when that day has no summary yet.
- A line whose write fails stops the run at that line; after three failed runs on the same line it is skipped, so the import never stalls. The last error is shown by the "Log import" health check and by the command output.
- When a store view is mapped to a log file, PHP tracking skips crawler hits for that store view so nothing is counted twice.

## Data and privacy

- The collector (`POST /panth_aiv/collect`) never starts a PHP session, never sets a session cookie and does not renew Magento's `private_content_version` cookie. The only cookie it may set is `panth_aiv` (signed, HttpOnly, SameSite=Lax), and only when cookie consent allows it.
- The collector sends only the referrer host name, the `utm_source` value and the landing path. No full referrer URLs, query strings, IP addresses or customer data are stored.
- Rate limiting stores only a salted hash of the IP address in short-lived counters. Requests are counted per client IP: at most "Max Reports per IP per Minute" beacons per client and 50 times that in total per minute.
- Crawler hit rows hold the bot, path, status and response time; visit rows hold the source, referrer host and landing path. Order attribution rows hold the order ID, the first and last AI source with their times, and the landing path.
- Recommendations and applied fixes store the admin user name that applied a fix and the optional note.
- The weekly summary email contains only aggregated numbers, page paths and titles; it is sent through the Magento mail transport to the configured recipients.
- All data stays in the Magento database. The module makes no outbound requests.

## Known limitations

- Google AI Overviews / AI Mode and Microsoft Copilot answers are fetched by Googlebot and Bingbot, so their share cannot be separated from ordinary search crawling.
- Errors that end a request before Magento sends its response (uncaught exceptions with a 500 page, the 503 maintenance page) are not recorded, so the 5xx share of crawler hits can be lower than in the web server log. Use the access log import for exact error rates.
- AI referrer visits are only attributed on the storefront domain that sets the cookie. Headless storefronts on another domain and cross-domain checkouts are not attributed.
- A visitor whose browser sends no referrer and no `utm_source` cannot be recognised as coming from an AI assistant; sampled direct landings show which deep pages are opened this way.
- Ordinary browsers that open `/llms.txt` are treated as people and never become Discovery candidates. AI agents that browse with an unmodified browser User-Agent cannot be told apart from shoppers.
- When a log file is rotated by renaming, lines written to the old file after the last import run (up to 5 minutes) can be missed.
- Discovery lists the 200 most active candidates per status.
- The "AI Source" column of the order grid sorts by source code, not by the displayed name.
- After a Discovery rule is deleted, its raw rows go back to the candidate; summaries of days whose raw rows were already removed keep the rule code and are shown as "Deleted rule (code)".

## Disabling and uninstalling

To stop collecting data, set **Enable AI Visibility** to "No" (store scope) or turn off crawler / visit tracking. This keeps all data.

Warning: `bin/magento module:disable Panth_AiVisibility` followed by `bin/magento setup:upgrade` drops all 19 `panth_aiv_*` tables (declarative schema), including the order attribution history. Back up the tables first (for example `mysqldump <database> $(mysql -N -e "SHOW TABLES LIKE 'panth_aiv_%'" <database>)`), or run `bin/magento setup:upgrade --safe-mode=1`, which keeps a backup of the dropped data in `var/declarative_dumps_csv/` that `setup:upgrade --data-restore=1` can restore.

To remove the module after taking a backup:

```bash
bin/magento module:disable Panth_AiVisibility
composer remove mage2kishan/module-ai-visibility
bin/magento setup:upgrade
bin/magento cache:flush
```

The configuration rows under `panth_aivisibility/` in `core_config_data` are left in place.

## Support

- Website: [kishansavaliya.com](https://kishansavaliya.com)
- Contact form: [kishansavaliya.com/contact](https://kishansavaliya.com/contact)
- Email: kishansavaliyakb@gmail.com

## License

Free and open source under the MIT License. See [LICENSE.txt](LICENSE.txt).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
