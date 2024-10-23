<?php

namespace JustBetter\SentryFilterEvents\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

class SentryFilterEventsCache extends TagScope
{
    const TYPE_IDENTIFIER = 'justbetter_sentry_filter_events';

    const CACHE_TAG = 'JUSTBETTER_SENTRY_FILTER_EVENTS';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct(
            $cacheFrontendPool->get(self::TYPE_IDENTIFIER),
            self::CACHE_TAG
        );
    }
}
