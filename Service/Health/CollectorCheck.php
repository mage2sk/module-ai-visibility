<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;

/**
 * The storefront snippet reports AI visits and sampled direct landings. Any real
 * (non-simulated) row in the last 7 days proves that it reaches the server.
 */
class CollectorCheck extends AbstractCheck
{
    public const CODE = 'collector';

    public const DAYS = 7;

    /**
     * @param ResourceConnection $resource
     * @param DateTime $dateTime
     * @param Config $config
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    protected function execute(): HealthResult
    {
        if (!$this->config->trackVisits()) {
            return new HealthResult(
                HealthResult::STATUS_UNKNOWN,
                (string)__(
                    'Visit tracking is switched off, so the collector is not used. To measure shoppers coming from '
                    . 'AI assistants, set "Track Visits from AI Assistants" to Yes in the AI Visibility configuration.'
                )
            );
        }
        $since = gmdate('Y-m-d H:i:s', (int)$this->dateTime->gmtTimestamp() - self::DAYS * 86400);
        $visits = $this->hasRows('panth_aiv_visit', $since);
        $landings = $visits ? false : $this->hasRows('panth_aiv_landing', $since);
        if ($visits || $landings) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__('The storefront collector is working: reports arrived in the last %1 days.', self::DAYS),
                ['visits' => $visits, 'landings' => $landings]
            );
        }
        return new HealthResult(
            HealthResult::STATUS_UNKNOWN,
            (string)__(
                'No AI visits or direct landings were recorded in the last %1 days. That is normal on a quiet store. '
                . 'To test: open a product page with ?utm_source=chatgpt.com in a private window, wait a few seconds, '
                . 'then run the checks again. If nothing arrives, make sure the page cache or a CDN/HTML minifier '
                . 'does not strip inline scripts and that the URL /panth_aiv/collect/index is not blocked.',
                self::DAYS
            )
        );
    }

    /**
     * Whether a non-simulated row exists since the given UTC time
     *
     * @param string $table
     * @param string $since
     * @return bool
     */
    private function hasRows(string $table, string $since): bool
    {
        $connection = $this->resource->getConnection();
        $found = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName($table), ['found' => new \Magento\Framework\DB\Sql\Expression('1')])
                ->where('created_at >= ?', $since)
                ->where('is_simulated = ?', 0)
                ->limit(1)
        );
        return $found !== false && $found !== null;
    }
}
