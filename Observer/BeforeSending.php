<?php

namespace JustBetter\SentryFilterEvents\Observer;

use InvalidArgumentException;
use JustBetter\SentryFilterEvents\Model\Cache\Type\SentryFilterEventsCache;
use Laminas\Http\Request;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Sentry\Event;
use Sentry\EventHint;

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
    ) {}

    /**
     * Filter event before dispatching it to sentry
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Event $event */
        $event = $observer->getEvent()->getSentryEvent()->getEvent();
        /** @var EventHint $hint */
        $hint = $observer->getEvent()->getSentryEvent()->getHint();

        /** @var string $hintMessage */
        $hintMessage = $hint?->exception?->getMessage() ?? $event->getMessage();
        $rawMessage = '';
        if ($hint?->exception instanceof LocalizedException) {
            // The untranslated string with parameters filled in.
            $hintMessage = $hint->exception->getLogMessage();
            // The untranslated string without parameters filled in.
            $rawMessage = $hint->exception->getRawMessage();
            $event->setMessage($hintMessage);

            $event->setContext('message', [
                'raw_message' => $rawMessage,
                'parameters' => $hint->exception->getParameters(),
                'message' => $hintMessage,
                'translated_message' => $hint->exception->getMessage()
            ]);

            $event->getExceptions()[0]->setValue($hintMessage);
            $observer->getEvent()->getSentryEvent()->setEvent($event);
        }

        $messages = array_merge($this->getCustomMessages(), $this->getDefaultMessages());

        foreach ($messages as $message) {
            if (str_contains($hintMessage, $message['message']) || str_contains($rawMessage, $message['message'])) {
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
