<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule\Support;

use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\GuardedRule;
use Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule\RuleDbTrait;
use PHPUnit\Framework\TestCase;

class GuardedRuleTest extends TestCase
{
    use RuleDbTrait;

    /**
     * Rule whose find() records the store and optionally throws
     *
     * @param \Throwable|null $error
     * @return GuardedRule
     */
    private function rule(?\Throwable $error): GuardedRule
    {
        return new class ($this->support(), $this->logger(), $error) extends GuardedRule {
            /**
             * @var int[]
             */
            public array $stores = [];

            /**
             * @var \Throwable|null
             */
            private ?\Throwable $error;

            public function __construct($support, $logger, ?\Throwable $error)
            {
                parent::__construct($support, $logger);
                $this->error = $error;
            }

            public function code(): string
            {
                return 'aivtest_guarded';
            }

            public function label(): string
            {
                return 'x';
            }

            public function metric(): string
            {
                return 'bot_hits';
            }

            protected function find(int $storeId, \DateTimeImmutable $today): array
            {
                $this->stores[] = $storeId;
                if ($this->error !== null) {
                    throw $this->error;
                }
                return [new Finding('aivtest_guarded', $storeId, 'site', 'site', 'T', [], 1, 0)];
            }
        };
    }

    public function testNegativeStoreCountsAsAllStores(): void
    {
        $rule = $this->rule(null);
        $findings = $rule->evaluate(-3, self::today());

        $this->assertSame([0], $rule->stores);
        $this->assertCount(1, $findings);
    }

    /**
     * A failing query must reach the engine (which keeps the rule's open rows and reports the error),
     * not turn into "no findings" (which would resolve every open recommendation of the rule)
     */
    public function testFailureIsNotSwallowed(): void
    {
        $error = new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        try {
            $this->rule($error)->evaluate(2, self::today());
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame($error, $e);
        }
        $this->assertSame([], $this->warnings, 'the engine logs the failure once, with the store');
    }
}
