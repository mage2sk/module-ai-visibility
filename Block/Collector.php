<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Config;

/**
 * Storefront AI visit snippet (Hyva and Luma).
 *
 * Renders an inert JSON config block plus a small vanilla JS snippet through SecureHtmlRenderer,
 * so Magento CSP adds the script hash / nonce and the snippet runs under a strict policy. The
 * output holds no visitor data, so it is safe in the full page cache.
 *
 * The snippet sends at most one AI/candidate beacon per browser session (a direct landing beacon
 * does not prevent a later AI beacon), never sends full referrer URLs or query strings: only the
 * referrer host name, the utm_source value and the landing path.
 */
class Collector extends Template
{
    public const CONFIG_ELEMENT_ID = 'panth-aiv-cfg';

    /**
     * Vanilla JS, no dependencies. Config keys: u collect URL, h host => code, s utm => code,
     * c candidate regex ('' = discovery off), n non-AI hosts, r landing sample rate (0-100).
     */
    public const SCRIPT = <<<'JS'
(function(){var w=window,d=document;function run(){try{var e=d.getElementById('panth-aiv-cfg'),s=w.sessionStorage,
k='panth_aiv',v;if(!e||!w.fetch||!s)return;v=s.getItem(k);if(v==='2')return;var c=JSON.parse(e.textContent),
l=w.location,h='',u='',p=(l.pathname||'/').slice(0,512),t='',x,re;try{x=new URL(d.referrer);
if(/^https?:$/.test(x.protocol))h=x.hostname.toLowerCase()}catch(z){}if(h&&h===l.hostname.toLowerCase())return;
try{u=(new URLSearchParams(l.search).get('utm_source')||'').trim().toLowerCase()}catch(z){}
if(!/^[a-z0-9._-]{1,64}$/.test(u))u='';function has(m,y){return Object.prototype.hasOwnProperty.call(m,y)}
function known(y){for(;y;){if(has(c.h,y))return 1;var i=y.indexOf('.');if(i<0)return 0;y=y.slice(i+1)}return 0}
function non(y){return c.n.some(function(n){if(n.slice(-2)==='.*'){n=n.slice(0,-1);
return y.indexOf(n)===0||y.indexOf('.'+n)>=0}return y===n||y.slice(-n.length-1)==='.'+n})}
re=c.c?new RegExp(c.c,'i'):0;if((h&&known(h))||(u&&has(c.s,u)))t='ai';
else if(re&&((h&&!non(h)&&re.test(h))||(u&&re.test(u))))t='cand';
else if(!h&&!v&&p!=='/'&&Math.random()*100<c.r)t='land';if(!t)return;s.setItem(k,t==='land'?'1':'2');
fetch(c.u,{method:'POST',body:JSON.stringify(t==='land'?{t:t,p:p}:{t:t,h:h,u:u,p:p}),keepalive:true,
credentials:'same-origin',headers:{'Content-Type':'text/plain'}}).catch(function(){})}catch(z){}}
function idle(){w.requestIdleCallback?w.requestIdleCallback(run,{timeout:3000}):setTimeout(run,1)}
d.readyState==='complete'?idle():w.addEventListener('load',idle)})();
JS;

    /**
     * @param Context $context
     * @param Config $config
     * @param SourceClassifierInterface $sourceClassifier
     * @param SecureHtmlRenderer $secureRenderer
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly SecureHtmlRenderer $secureRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the snippet is rendered for the current store view
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        try {
            $storeId = (int)$this->_storeManager->getStore()->getId();
            return $this->config->isEnabled($storeId) && $this->config->trackVisits();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Collector URL for the current store view
     *
     * @return string
     */
    public function getCollectUrl(): string
    {
        return $this->getUrl('panth_aiv/collect/index', ['_secure' => $this->getRequest()->isSecure()]);
    }

    /**
     * JSON config for the snippet, safe to place inside a script element
     *
     * @return string
     */
    public function getConfigJson(): string
    {
        $client = $this->sourceClassifier->clientConfig();
        $config = [
            'u' => $this->getCollectUrl(),
            'h' => (object)($client['hosts'] ?? []),
            's' => (object)($client['utm'] ?? []),
            'c' => $this->config->discoveryEnabled() ? (string)($client['candidateRegex'] ?? '') : '',
            'n' => array_values($client['nonAiHosts'] ?? []),
            'r' => $this->config->landingSampleRate(),
        ];
        return (string)json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * The snippet as an inline script registered with Magento CSP
     *
     * @return string
     */
    public function renderScript(): string
    {
        return $this->secureRenderer->renderTag('script', [], self::SCRIPT, false);
    }
}
