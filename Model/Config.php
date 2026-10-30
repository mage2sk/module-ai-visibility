<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Source\AttributionModel;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Typed access to the panth_aivisibility configuration section.
 *
 * Only general/enabled is read per store view; every other value is global (default scope).
 * Values that are not whole numbers fall back to the default; numbers outside the allowed
 * range are clamped to the nearest bound.
 */
class Config
{
    public const SECTION = 'panth_aivisibility';

    public const XML_PATH_ENABLED = 'panth_aivisibility/general/enabled';
    public const XML_PATH_TRACK_CRAWLERS = 'panth_aivisibility/general/track_crawlers';
    public const XML_PATH_TRACK_VISITS = 'panth_aivisibility/general/track_visits';
    public const XML_PATH_ATTRIBUTION_WINDOW_DAYS = 'panth_aivisibility/general/attribution_window_days';
    public const XML_PATH_ATTRIBUTION_MODEL = 'panth_aivisibility/general/attribution_model';
    public const XML_PATH_DISCOVERY_ENABLED = 'panth_aivisibility/bots/discovery_enabled';
    public const XML_PATH_CUSTOM_BOT_PATTERNS = 'panth_aivisibility/bots/custom_patterns';
    public const XML_PATH_RAW_DAILY_CAP = 'panth_aivisibility/bots/raw_daily_cap';
    public const XML_PATH_CUSTOM_HOSTS = 'panth_aivisibility/sources/custom_hosts';
    public const XML_PATH_CUSTOM_UTM = 'panth_aivisibility/sources/custom_utm';
    public const XML_PATH_RATE_LIMIT_PER_MINUTE = 'panth_aivisibility/collector/rate_limit_per_minute';
    public const XML_PATH_LANDING_SAMPLE_RATE = 'panth_aivisibility/collector/landing_sample_rate';
    public const XML_PATH_RESPECT_COOKIE_CONSENT = 'panth_aivisibility/privacy/respect_cookie_consent';
    public const XML_PATH_HIT_RETENTION_DAYS = 'panth_aivisibility/privacy/hit_retention_days';
    public const XML_PATH_RAW_VISIT_RETENTION_DAYS = 'panth_aivisibility/privacy/raw_visit_retention_days';
    public const XML_PATH_LANDING_RETENTION_DAYS = 'panth_aivisibility/privacy/landing_retention_days';
    public const XML_PATH_DAILY_RETENTION_MONTHS = 'panth_aivisibility/privacy/daily_retention_months';
    public const XML_PATH_LOG_IMPORT_ENABLED = 'panth_aivisibility/log_import/enabled';
    public const XML_PATH_LOG_IMPORT_FILES = 'panth_aivisibility/log_import/files';
    public const XML_PATH_LOG_IMPORT_MAX_LINES = 'panth_aivisibility/log_import/max_lines_per_run';
    public const XML_PATH_RECOMMENDATIONS_ENABLED = 'panth_aivisibility/recommendations/enabled';
    public const XML_PATH_RECOMMENDATIONS_MIN_VOLUME = 'panth_aivisibility/recommendations/min_volume';
    public const XML_PATH_RECOMMENDATIONS_MAX_OPEN = 'panth_aivisibility/recommendations/max_open';
    public const XML_PATH_VERIFY_WINDOW_DAYS = 'panth_aivisibility/verify/window_days';
    public const XML_PATH_VERIFY_MIN_EVENTS = 'panth_aivisibility/verify/min_events';
    public const XML_PATH_VERIFY_MAX_CONTROLS = 'panth_aivisibility/verify/max_controls';
    public const XML_PATH_EMAIL_ENABLED = 'panth_aivisibility/email/enabled';
    public const XML_PATH_EMAIL_RECIPIENTS = 'panth_aivisibility/email/recipients';
    public const XML_PATH_EMAIL_SENDER = 'panth_aivisibility/email/sender';
    public const XML_PATH_EMAIL_WEEKDAY = 'panth_aivisibility/email/weekday';
    public const XML_PATH_EMAIL_HOUR = 'panth_aivisibility/email/hour';

    /**
     * Magento's "Add Store Code to Urls" setting (web/url/use_store)
     */
    public const XML_PATH_STORE_CODE_IN_URL = 'web/url/use_store';

    public const CRAWLER_SOURCE_MAGENTO = 'magento';
    public const CRAWLER_SOURCE_LOG = 'log';

    /**
     * Minimum length of a custom User-Agent token, so a typo cannot match every browser
     */
    public const MIN_BOT_TOKEN_LENGTH = 3;

    /**
     * Email template of the weekly summary (etc/email_templates.xml)
     */
    public const EMAIL_TEMPLATE_WEEKLY_SUMMARY = 'panth_aivisibility_weekly_summary';

    /**
     * Max recipients of the weekly summary
     */
    public const EMAIL_MAX_RECIPIENTS = 20;

    /**
     * Magento store email identities (Stores > Configuration > General > Store Email Addresses)
     */
    public const EMAIL_IDENTITIES = ['general', 'sales', 'support', 'custom1', 'custom2'];
    public const EMAIL_DEFAULT_IDENTITY = 'general';

    /**
     * Integer settings: path => [default, min, max]
     */
    private const INT_LIMITS = [
        self::XML_PATH_ATTRIBUTION_WINDOW_DAYS => [30, 1, 365],
        self::XML_PATH_RAW_DAILY_CAP => [200000, 1000, 10000000],
        self::XML_PATH_RATE_LIMIT_PER_MINUTE => [20, 1, 600],
        self::XML_PATH_LANDING_SAMPLE_RATE => [25, 0, 100],
        self::XML_PATH_HIT_RETENTION_DAYS => [14, 1, 365],
        self::XML_PATH_RAW_VISIT_RETENTION_DAYS => [30, 1, 730],
        self::XML_PATH_LANDING_RETENTION_DAYS => [30, 1, 365],
        self::XML_PATH_DAILY_RETENTION_MONTHS => [13, 1, 60],
        self::XML_PATH_LOG_IMPORT_MAX_LINES => [200000, 1000, 5000000],
        self::XML_PATH_RECOMMENDATIONS_MIN_VOLUME => [20, 1, 1000000],
        self::XML_PATH_RECOMMENDATIONS_MAX_OPEN => [50, 1, 500],
        self::XML_PATH_VERIFY_WINDOW_DAYS => [14, 7, 56],
        self::XML_PATH_VERIFY_MIN_EVENTS => [30, 1, 1000000],
        self::XML_PATH_VERIFY_MAX_CONTROLS => [50, 3, 500],
        self::XML_PATH_EMAIL_WEEKDAY => [1, 1, 7],
        self::XML_PATH_EMAIL_HOUR => [7, 0, 23],
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Master switch for a store view (null = current store)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether AI crawler hits are recorded
     *
     * @return bool
     */
    public function trackCrawlers(): bool
    {
        return $this->flag(self::XML_PATH_TRACK_CRAWLERS);
    }

    /**
     * Whether AI assistant visits are recorded (storefront collector)
     *
     * @return bool
     */
    public function trackVisits(): bool
    {
        return $this->flag(self::XML_PATH_TRACK_VISITS);
    }

    /**
     * Attribution window in days (1-365, default 30)
     *
     * @return int
     */
    public function attributionWindowDays(): int
    {
        return $this->intValue(self::XML_PATH_ATTRIBUTION_WINDOW_DAYS);
    }

    /**
     * Attribution model: first | last (default first)
     *
     * @return string
     */
    public function attributionModel(): string
    {
        $value = strtolower(trim((string)$this->scopeConfig->getValue(self::XML_PATH_ATTRIBUTION_MODEL)));
        return $value === AttributionModel::LAST ? AttributionModel::LAST : AttributionModel::FIRST;
    }

    /**
     * Whether unknown bots and sources are collected as discovery candidates
     *
     * @return bool
     */
    public function discoveryEnabled(): bool
    {
        return $this->flag(self::XML_PATH_DISCOVERY_ENABLED);
    }

    /**
     * Custom AI bots from "Name | ua-token | category[ | company]" lines
     *
     * Invalid lines are skipped; unknown categories become "other"; a missing company
     * defaults to the name. The first line wins when a token is listed twice.
     *
     * @return array<int, array{name: string, token: string, category: string, company: string}>
     */
    public function customBotPatterns(): array
    {
        $result = [];
        $seen = [];
        foreach ($this->lines(self::XML_PATH_CUSTOM_BOT_PATTERNS) as $parts) {
            $name = $this->cut($parts[0], 128);
            $token = $this->cut($parts[1] ?? '', 512);
            if ($name === '' || strlen($token) < self::MIN_BOT_TOKEN_LENGTH) {
                continue;
            }
            $key = strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $category = strtolower($parts[2] ?? '');
            $company = $this->cut($parts[3] ?? '', 64);
            $result[] = [
                'name' => $name,
                'token' => $token,
                'category' => Category::isValid($category) ? $category : Category::OTHER,
                'company' => $company !== '' ? $company : $this->cut($name, 64),
            ];
        }
        return $result;
    }

    /**
     * Daily cap of raw hit rows (1000-10000000, default 200000)
     *
     * @return int
     */
    public function rawDailyCap(): int
    {
        return $this->intValue(self::XML_PATH_RAW_DAILY_CAP);
    }

    /**
     * Custom AI referrer hosts from "host | Source Name" lines: host => name
     *
     * Hosts are lower-cased; a pasted scheme, port or path is removed. A missing name defaults to the host.
     *
     * @return array<string, string>
     */
    public function customSourceHosts(): array
    {
        $result = [];
        foreach ($this->lines(self::XML_PATH_CUSTOM_HOSTS) as $parts) {
            $host = $this->normalizeHost($parts[0]);
            if ($host === null || isset($result[$host])) {
                continue;
            }
            $name = $this->cut($parts[1] ?? '', 128);
            $result[$host] = $name !== '' ? $name : $host;
        }
        return $result;
    }

    /**
     * Custom AI utm_source values from "utm_value | Source Name" lines: utm => name
     *
     * Values are lower-cased and must match [a-z0-9._-]{1,64}. A missing name defaults to the value.
     *
     * @return array<string, string>
     */
    public function customUtm(): array
    {
        $result = [];
        foreach ($this->lines(self::XML_PATH_CUSTOM_UTM) as $parts) {
            $utm = strtolower($parts[0]);
            if (!preg_match('/^[a-z0-9._-]{1,64}$/', $utm) || isset($result[$utm])) {
                continue;
            }
            $name = $this->cut($parts[1] ?? '', 128);
            $result[$utm] = $name !== '' ? $name : $utm;
        }
        return $result;
    }

    /**
     * Collector rate limit per IP and minute (1-600, default 20)
     *
     * @return int
     */
    public function rateLimitPerMinute(): int
    {
        return $this->intValue(self::XML_PATH_RATE_LIMIT_PER_MINUTE);
    }

    /**
     * Direct landing sample rate in percent (0-100, default 25)
     *
     * @return int
     */
    public function landingSampleRate(): int
    {
        return $this->intValue(self::XML_PATH_LANDING_SAMPLE_RATE);
    }

    /**
     * Whether Magento cookie restriction mode is honoured for the attribution cookie
     *
     * @return bool
     */
    public function respectCookieConsent(): bool
    {
        return $this->flag(self::XML_PATH_RESPECT_COOKIE_CONSENT);
    }

    /**
     * Days to keep raw hit rows (1-365, default 14)
     *
     * @return int
     */
    public function hitRetentionDays(): int
    {
        return $this->intValue(self::XML_PATH_HIT_RETENTION_DAYS);
    }

    /**
     * Days to keep raw visit rows (1-730, default 30)
     *
     * @return int
     */
    public function rawVisitRetentionDays(): int
    {
        return $this->intValue(self::XML_PATH_RAW_VISIT_RETENTION_DAYS);
    }

    /**
     * Days to keep direct landing rows (1-365, default 30)
     *
     * @return int
     */
    public function landingRetentionDays(): int
    {
        return $this->intValue(self::XML_PATH_LANDING_RETENTION_DAYS);
    }

    /**
     * Months to keep the daily summary tables (1-60, default 13)
     *
     * @return int
     */
    public function dailyRetentionMonths(): int
    {
        return $this->intValue(self::XML_PATH_DAILY_RETENTION_MONTHS);
    }

    /**
     * Whether the access log import is enabled
     *
     * @return bool
     */
    public function logImportEnabled(): bool
    {
        return $this->flag(self::XML_PATH_LOG_IMPORT_ENABLED);
    }

    /**
     * Log files from "/abs/path/access.log | store_code" lines
     *
     * Paths must be absolute (max 1024 characters). The store code is lower-cased and
     * defaults to "default". The first line wins when a path is listed twice.
     *
     * @return array<int, array{path: string, store_code: string}>
     */
    public function logImportFiles(): array
    {
        $result = [];
        $seen = [];
        foreach ($this->lines(self::XML_PATH_LOG_IMPORT_FILES) as $parts) {
            $path = $parts[0];
            if (!str_starts_with($path, '/') || strlen($path) > 1024 || str_contains($path, "\0")) {
                continue;
            }
            $storeCode = strtolower($parts[1] ?? '');
            if ($storeCode === '') {
                $storeCode = 'default';
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $storeCode) || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $result[] = ['path' => $path, 'store_code' => $storeCode];
        }
        return $result;
    }

    /**
     * Max log lines per import run (1000-5000000, default 200000)
     *
     * @return int
     */
    public function logImportMaxLines(): int
    {
        return $this->intValue(self::XML_PATH_LOG_IMPORT_MAX_LINES);
    }

    /**
     * Where crawler hits of a store view come from: "log" or "magento"
     *
     * "log" when the access log import is enabled and the store's code is mapped to a log file;
     * TrackRequest then skips the store so no hit is counted twice.
     *
     * @param int $storeId
     * @return string
     */
    public function crawlerSource(int $storeId): string
    {
        if (!$this->logImportEnabled()) {
            return self::CRAWLER_SOURCE_MAGENTO;
        }
        $codes = array_column($this->logImportFiles(), 'store_code');
        if ($codes === []) {
            return self::CRAWLER_SOURCE_MAGENTO;
        }
        try {
            $code = strtolower((string)$this->storeManager->getStore($storeId)->getCode());
        } catch (\Throwable $e) {
            return self::CRAWLER_SOURCE_MAGENTO;
        }
        return in_array($code, $codes, true) ? self::CRAWLER_SOURCE_LOG : self::CRAWLER_SOURCE_MAGENTO;
    }

    /**
     * Whether the daily recommendation engine runs
     *
     * @return bool
     */
    public function recommendationsEnabled(): bool
    {
        return $this->flag(self::XML_PATH_RECOMMENDATIONS_ENABLED);
    }

    /**
     * Minimum number of events behind a finding; smaller findings are ignored (1-1000000, default 20)
     *
     * @return int
     */
    public function recommendationsMinVolume(): int
    {
        return $this->intValue(self::XML_PATH_RECOMMENDATIONS_MIN_VOLUME);
    }

    /**
     * Max open recommendations kept per store view (1-500, default 50)
     *
     * @return int
     */
    public function recommendationsMaxOpen(): int
    {
        return $this->intValue(self::XML_PATH_RECOMMENDATIONS_MAX_OPEN);
    }

    /**
     * Verification window in days before and after a fix (7-56, default 14)
     *
     * @return int
     */
    public function verifyWindowDays(): int
    {
        return $this->intValue(self::XML_PATH_VERIFY_WINDOW_DAYS);
    }

    /**
     * Minimum events needed for a verdict; fewer give "insufficient" (1-1000000, default 30)
     *
     * @return int
     */
    public function verifyMinEvents(): int
    {
        return $this->intValue(self::XML_PATH_VERIFY_MIN_EVENTS);
    }

    /**
     * Max control subjects matched per verified action (3-500, default 50)
     *
     * @return int
     */
    public function verifyMaxControls(): int
    {
        return $this->intValue(self::XML_PATH_VERIFY_MAX_CONTROLS);
    }

    /**
     * Whether the weekly summary email is sent
     *
     * @return bool
     */
    public function emailEnabled(): bool
    {
        return $this->flag(self::XML_PATH_EMAIL_ENABLED);
    }

    /**
     * Valid weekly summary recipients (comma, semicolon or newline separated; invalid addresses skipped)
     *
     * Duplicates (case-insensitive) are removed and at most EMAIL_MAX_RECIPIENTS are returned.
     *
     * @return string[]
     */
    public function emailRecipients(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENTS);
        $valid = self::splitEmailList(is_string($raw) ? $raw : '')['valid'];
        return array_slice($valid, 0, self::EMAIL_MAX_RECIPIENTS);
    }

    /**
     * Store email identity used as sender: general | sales | support | custom1 | custom2 (default general)
     *
     * @return string
     */
    public function emailSender(): string
    {
        $value = strtolower(trim((string)$this->scopeConfig->getValue(self::XML_PATH_EMAIL_SENDER)));
        return in_array($value, self::EMAIL_IDENTITIES, true) ? $value : self::EMAIL_DEFAULT_IDENTITY;
    }

    /**
     * ISO weekday the summary is sent on: 1 = Monday ... 7 = Sunday (default 1)
     *
     * @return int
     */
    public function emailWeekday(): int
    {
        return $this->intValue(self::XML_PATH_EMAIL_WEEKDAY);
    }

    /**
     * Hour of the day (UTC, 0-23) the summary is sent at (default 7)
     *
     * @return int
     */
    public function emailHour(): int
    {
        return $this->intValue(self::XML_PATH_EMAIL_HOUR);
    }

    /**
     * Split an address list on commas, semicolons, whitespace and new lines into valid and invalid addresses
     *
     * Valid addresses are de-duplicated case-insensitively (first spelling kept), order is kept.
     *
     * @param string $raw
     * @return array{valid: string[], invalid: string[]}
     */
    public static function splitEmailList(string $raw): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $address;
                continue;
            }
            $key = strtolower($address);
            if (!isset($valid[$key])) {
                $valid[$key] = $address;
            }
        }
        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    /**
     * Store code that prefixes this store's URLs ("Add Store Code to Urls"), or '' when URLs have none
     *
     * Uses the store's own isUseStoreInUrl() when available (it also honours single-store mode and
     * stores that disable the code in URLs), otherwise the web/url/use_store flag.
     *
     * @param int $storeId
     * @return string
     */
    public function urlStoreCode(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
            $inUrl = method_exists($store, 'isUseStoreInUrl')
                ? (bool)$store->isUseStoreInUrl()
                : $this->scopeConfig->isSetFlag(self::XML_PATH_STORE_CODE_IN_URL);
            return $inUrl ? strtolower(trim((string)$store->getCode())) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Read a global yes/no flag
     *
     * @param string $path
     * @return bool
     */
    private function flag(string $path): bool
    {
        return $this->scopeConfig->isSetFlag($path);
    }

    /**
     * Read a global integer setting, falling back to its default and clamping it to its range
     *
     * @param string $path
     * @return int
     */
    private function intValue(string $path): int
    {
        [$default, $min, $max] = self::INT_LIMITS[$path];
        $raw = $this->scopeConfig->getValue($path);
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\s*[+-]?\d{1,18}\s*$/', $raw)) {
            $value = (int)trim($raw);
        } else {
            return $default;
        }
        return max($min, min($max, $value));
    }

    /**
     * Split a textarea setting into trimmed, pipe-separated parts per non-empty line
     *
     * Lines starting with "#" are comments.
     *
     * @param string $path
     * @return array<int, array<int, string>>
     */
    private function lines(string $path): array
    {
        $raw = $this->scopeConfig->getValue($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $result[] = array_map('trim', explode('|', $line));
        }
        return $result;
    }

    /**
     * Normalise a host name; null when it is not a valid host
     *
     * @param string $value
     * @return string|null
     */
    private function normalizeHost(string $value): ?string
    {
        $host = strtolower(trim($value));
        $host = (string)preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = (string)preg_replace('#[/?\#].*$#', '', $host);
        $host = (string)preg_replace('#:\d*$#', '', $host);
        $host = trim($host, '.');
        if ($host === '' || strlen($host) > 255 || str_contains($host, '..')
            || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)
        ) {
            return null;
        }
        return $host;
    }

    /**
     * Trim and cut a string to a maximum number of characters
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    private function cut(string $value, int $length): string
    {
        return trim(mb_substr(trim($value), 0, $length));
    }
}
