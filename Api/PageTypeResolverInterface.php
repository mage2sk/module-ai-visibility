<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Maps a URL path to a coarse page type, from the path alone (no database access).
 */
interface PageTypeResolverInterface
{
    public const TYPE_HOME = 'home';
    public const TYPE_LLMS = 'llms';
    public const TYPE_ROBOTS = 'robots';
    public const TYPE_SITEMAP = 'sitemap';
    public const TYPE_SEARCH = 'search';
    public const TYPE_PAGE = 'page';
    public const TYPE_OTHER = 'other';

    /**
     * Page type for a URL path: home | llms | robots | sitemap | search | page | other
     *
     * @param string $path URL path without query string
     * @return string
     */
    public function resolve(string $path): string;
}
