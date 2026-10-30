<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Observer;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\AiVisibility\Model\RuleProvider;
use Psr\Log\LoggerInterface;

/**
 * Clears the cached Discovery rules when the AI Visibility configuration is saved, so custom bot
 * patterns and confirmed rules are always read together.
 */
class CleanRuleCache implements ObserverInterface
{
    /**
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        try {
            $this->cache->clean([RuleProvider::CACHE_TAG]);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] rule cache could not be cleaned: ' . $e->getMessage());
        }
    }
}
