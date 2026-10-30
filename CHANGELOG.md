# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-30

### Added
- Counting of AI crawler requests (training, search and live-answer bots), including pages served from the built-in full page cache, with a daily cap on detailed rows.
- Detection of shoppers arriving from AI assistants by referrer host and `utm_source`, through a small CSP-safe storefront collector for Hyva and Luma that starts no session.
- Order attribution with first-touch and last-touch models through a signed first-party cookie that respects cookie consent, with an "AI Source" column and filter in the order grid and an "AI Attribution" block on the order view. One-page and multishipping checkout are supported.
- Admin dashboard with KPIs, charts, top pages, bots, visits and revenue by source, and insights for `robots.txt` blocks, `llms.txt` fetches, products not fetched by AI bots and bot error rates.
- Crawler Log grid with filters and export.
- Discovery page for unknown AI bots and AI referrer apps with Confirm, Not AI and Ignore actions.
- Seven data-health checks shown on the dashboard.
- Access log import for stores behind Varnish or a CDN, with resume, rotation detection and locking.
- Deterministic traffic simulator for testing, with a purge option.
- Console commands `panth:aivisibility:rollup`, `panth:aivisibility:import-logs` and `panth:aivisibility:simulate`, and five cron jobs for daily summaries, retention, Discovery, health checks and the log import.
- Configurable retention for detailed rows and daily summaries.
