<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Panth\AiVisibility\Api\PageTypeResolverInterface;

/**
 * Coarse page type from the URL path alone (no database access).
 *
 * "page" means "may be a catalogue or CMS URL": the rollup resolves those through url_rewrite.
 * Known system routes, static files and paths with a non-page file extension are "other".
 */
class PageTypeResolver implements PageTypeResolverInterface
{
    /**
     * First path segments of Magento routes that never are catalogue or CMS pages
     */
    private const SYSTEM_SEGMENTS = [
        'checkout', 'customer', 'wishlist', 'sales', 'review', 'rest', 'graphql', 'soap', 'newsletter',
        'contact', 'sendfriend', 'paypal', 'braintree', 'multishipping', 'downloadable', 'vault',
        'static', 'media', 'pub', 'panth_aiv', 'page_cache', 'customer-data', 'swagger',
        'catalog', 'cms', 'directory', 'persistent', 'productalert', 'captcha', 'oauth', 'admin',
    ];

    /**
     * File extensions that are pages (anything else with an extension is a file: "other")
     */
    private const PAGE_EXTENSIONS = ['html', 'htm', 'php'];

    /**
     * @inheritdoc
     */
    public function resolve(string $path): string
    {
        $path = strtolower(trim($path));
        $cut = strcspn($path, '?#');
        $path = substr($path, 0, $cut);
        $path = (string)preg_replace('#/{2,}#', '/', '/' . ltrim($path, '/'));
        if (str_starts_with($path, '/index.php')
            && (strlen($path) === 10 || $path[10] === '/')
        ) {
            $path = '/' . ltrim(substr($path, 10), '/');
        }
        if ($path === '/') {
            return self::TYPE_HOME;
        }
        $trimmed = rtrim($path, '/');
        $slash = strrpos($trimmed, '/');
        $basename = $slash === false ? $trimmed : substr($trimmed, $slash + 1);

        if ($basename === 'llms.txt' || $basename === 'llms-full.txt') {
            return self::TYPE_LLMS;
        }
        if ($basename === 'robots.txt') {
            return self::TYPE_ROBOTS;
        }
        if (preg_match('/^sitemap[^\/]*\.xml(\.gz)?$/', $basename)) {
            return self::TYPE_SITEMAP;
        }
        $segments = explode('/', trim($trimmed, '/'));
        $first = $segments[0];
        if ($first === 'catalogsearch' || $first === 'search') {
            return self::TYPE_SEARCH;
        }
        if (in_array($first, self::SYSTEM_SEGMENTS, true)) {
            return self::TYPE_OTHER;
        }
        $dot = strrpos($basename, '.');
        if ($dot !== false && !in_array(substr($basename, $dot + 1), self::PAGE_EXTENSIONS, true)) {
            return self::TYPE_OTHER;
        }
        return self::TYPE_PAGE;
    }
}
