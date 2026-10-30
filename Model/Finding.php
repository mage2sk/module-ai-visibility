<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

/**
 * One problem a recommendation rule found on one subject (site, bot, path, product, category or source).
 *
 * Immutable value object returned by RecommendationRuleInterface::evaluate(). The engine turns it into a
 * panth_aiv_recommendation row (upsert by fingerprint()); subjects are the refs an applied fix affects and
 * are kept in the stored evidence JSON under RecommendationRepositoryInterface::EVIDENCE_SUBJECTS.
 * Not declared final only because the Magento coding standard forbids final classes; do not extend it.
 */
class Finding
{
    public const SUBJECT_SITE = 'site';
    public const SUBJECT_BOT = 'bot';
    public const SUBJECT_PATH = 'path';
    public const SUBJECT_PRODUCT = 'product';
    public const SUBJECT_CATEGORY = 'category';
    public const SUBJECT_SOURCE = 'source';

    public const SUBJECT_TYPES = [
        self::SUBJECT_SITE,
        self::SUBJECT_BOT,
        self::SUBJECT_PATH,
        self::SUBJECT_PRODUCT,
        self::SUBJECT_CATEGORY,
        self::SUBJECT_SOURCE,
    ];

    /**
     * Column limits of panth_aiv_recommendation
     */
    public const MAX_RULE_CODE = 32;
    public const MAX_SUBJECT_REF = 512;
    public const MAX_TITLE = 255;
    public const MAX_METRIC = 32;
    public const MAX_IMPACT_SCORE = 100;

    /**
     * @var string
     */
    public readonly string $ruleCode;

    /**
     * @var int
     */
    public readonly int $storeId;

    /**
     * @var string
     */
    public readonly string $subjectType;

    /**
     * @var string
     */
    public readonly string $subjectRef;

    /**
     * @var string
     */
    public readonly string $title;

    /**
     * @var array
     */
    public readonly array $evidence;

    /**
     * @var int
     */
    public readonly int $affectedCount;

    /**
     * @var int
     */
    public readonly int $impactScore;

    /**
     * @var string
     */
    public readonly string $metric;

    /**
     * @var string[]
     */
    public readonly array $subjects;

    /**
     * Values are normalised: strings trimmed and cut to their column length, an unknown subject type
     * becomes "site", negative counts become 0, the impact score is clamped to 0-100 and subjects are
     * de-duplicated non-empty strings (order kept). An empty subject list defaults to [subjectRef].
     *
     * @param string $ruleCode Rule code, e.g. "bot_404" (max 32)
     * @param int $storeId Store view ID (0 = all store views)
     * @param string $subjectType site | bot | path | product | category | source
     * @param string $subjectRef 'site', bot code, path, entity id or source code (max 512)
     * @param string $title Admin-readable one-line summary (max 255)
     * @param array $evidence JSON-serialisable details shown with the recommendation
     * @param int $affectedCount Events / items behind the finding (>= 0)
     * @param int $impactScore 0-100
     * @param string $metric MetricProviderInterface::METRIC_* used to verify a fix
     * @param string[] $subjects Subject refs an applied fix affects
     */
    public function __construct(
        string $ruleCode,
        int $storeId,
        string $subjectType,
        string $subjectRef,
        string $title,
        array $evidence = [],
        int $affectedCount = 0,
        int $impactScore = 0,
        string $metric = '',
        array $subjects = []
    ) {
        $this->ruleCode = self::cut($ruleCode, self::MAX_RULE_CODE);
        $this->storeId = max(0, $storeId);
        $type = strtolower(trim($subjectType));
        $this->subjectType = in_array($type, self::SUBJECT_TYPES, true) ? $type : self::SUBJECT_SITE;
        $this->subjectRef = self::cut($subjectRef, self::MAX_SUBJECT_REF);
        $this->title = self::cut($title, self::MAX_TITLE);
        $this->evidence = $evidence;
        $this->affectedCount = max(0, $affectedCount);
        $this->impactScore = max(0, min(self::MAX_IMPACT_SCORE, $impactScore));
        $this->metric = self::cut($metric, self::MAX_METRIC);
        $clean = [];
        foreach ($subjects as $subject) {
            if (!is_scalar($subject)) {
                continue;
            }
            $subject = self::cut((string)$subject, self::MAX_SUBJECT_REF);
            if ($subject !== '' && !isset($clean[$subject])) {
                $clean[$subject] = true;
            }
        }
        $list = array_map('strval', array_keys($clean));
        $this->subjects = $list === [] && $this->subjectRef !== '' ? [$this->subjectRef] : $list;
    }

    /**
     * Stable identity of the finding: sha1("rule_code|store_id|subject_ref"), 40 hex characters
     *
     * @return string
     */
    public function fingerprint(): string
    {
        return sha1($this->ruleCode . '|' . $this->storeId . '|' . $this->subjectRef);
    }

    /**
     * Copy with another impact score (the engine normalises scores across findings)
     *
     * @param int $impactScore
     * @return Finding
     */
    public function withImpactScore(int $impactScore): Finding
    {
        return new Finding(
            $this->ruleCode,
            $this->storeId,
            $this->subjectType,
            $this->subjectRef,
            $this->title,
            $this->evidence,
            $this->affectedCount,
            $impactScore,
            $this->metric,
            $this->subjects
        );
    }

    /**
     * Plain array (snake_case keys) for dry-run output and tests
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint(),
            'rule_code' => $this->ruleCode,
            'store_id' => $this->storeId,
            'subject_type' => $this->subjectType,
            'subject_ref' => $this->subjectRef,
            'title' => $this->title,
            'evidence' => $this->evidence,
            'affected_count' => $this->affectedCount,
            'impact_score' => $this->impactScore,
            'metric' => $this->metric,
            'subjects' => $this->subjects,
        ];
    }

    /**
     * Trim and cut a string to a maximum number of characters
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    private static function cut(string $value, int $length): string
    {
        return trim(mb_substr(trim($value), 0, $length));
    }
}
