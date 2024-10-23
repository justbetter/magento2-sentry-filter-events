<?php

namespace JustBetter\SentryFilterEvents\Observer;

use Laminas\Http\Request;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use JustBetter\SentryFilterEvents\Model\Cache\Type\SentryFilterEventsCache;
use InvalidArgumentException;

class BeforeSending implements ObserverInterface
{
    protected const CACHE_TTL = 604800;
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     */
    public function __construct(
        protected ScopeConfigInterface    $scopeConfig,
        protected Json                    $json,
        protected CurlFactory             $curlFactory,
        protected SentryFilterEventsCache $cache
    )
    {
    }

    /**
     * Filter event before dispatching it to sentry
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent()->getSentryEvent()->getEvent();
        $hint = $observer->getEvent()->getSentryEvent()->getHint();

        $hintMessage = $hint?->exception?->getMessage() ?? $event->getMessage();
        if ($hint?->exception instanceof LocalizedException) {
            $hintMessage = $hint->exception->getRawMessage();
            $event->setMessage($hintMessage);
            $event->getExceptions()[0]->setValue($hintMessage);
            $observer->getEvent()->getSentryEvent()->setEvent($event);
        }

        $messages = array_merge($this->getCustomMessages(), $this->getDefaultMessages());

        foreach ($messages as $message) {
            if (str_contains($hintMessage, $message['message'])) {
                $observer->getEvent()->getSentryEvent()->unsEvent();
                break;
            }
        }

    }

    private function getCustomMessages(): array
    {
        return array_values($this->json->unserialize($this->scopeConfig->getValue('sentry/event_filtering/messages')));
    }

    private function getDefaultMessages(): array
    {
        if ($cachedDefaultMessages = $this->cache->load(SentryFilterEventsCache::TYPE_IDENTIFIER)) {
            return $this->json->unserialize((string)$cachedDefaultMessages, true) ?? [];
        }

        $url = $this->scopeConfig->getValue('sentry/event_filtering/default_messages_external_location');

        if (!$url) {
            return [];
        }

        /** @var \Magento\Framework\HTTP\Adapter\Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setOptions([
            'timeout' => 10,
            'header' => false,
            'verifypeer' => false
        ]);
        $curl->write(Request::METHOD_GET, $url, '1.1', []);

        $responseData = $curl->read();

        if ($responseData === false || !$responseData) {
            return [];
        }
        $curl->close();
        $this->cache->save($responseData, SentryFilterEventsCache::TYPE_IDENTIFIER, [SentryFilterEventsCache::CACHE_TAG], self::CACHE_TTL);

        try {
            return $this->json->unserialize((string)$responseData, true) ?? [];
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
