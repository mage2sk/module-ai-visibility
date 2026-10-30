<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Moves the raw rows of a confirmed discovery candidate onto its new code.
 */
interface RelabelerInterface
{
    /**
     * Relabel rows stored as "cand:<id>" and re-roll every affected day
     *
     * @param int $candidateId
     * @param string $type bot | source
     * @param string $code New bot or source code
     * @param string|null $category Bot category (training | search | live | other); ignored for sources
     * @return int Number of raw rows updated
     */
    public function relabelCandidate(int $candidateId, string $type, string $code, ?string $category = null): int;

    /**
     * Move rows stored under a deleted rule's code back to "cand:<id>" and re-roll every affected day
     *
     * @param int $candidateId
     * @param string $type bot | source
     * @param string $code Code of the deleted rule
     * @return int Number of raw rows updated
     */
    public function restoreCandidate(int $candidateId, string $type, string $code): int;
}
