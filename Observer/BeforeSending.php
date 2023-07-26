<?php

namespace JustBetter\SentryFilterEvents\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;

class BeforeSending implements ObserverInterface
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     */
    public function __construct(
        protected ScopeConfigInterface $scopeConfig,
        protected Json                 $json
    ) { }

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

        $messages = $this->json->unserialize($this->scopeConfig->getValue('sentry/event_filtering/messages'));
        foreach ($messages as $message) {
            if (str_contains($hintMessage, $message['message'])) {
                $observer->getEvent()->getSentryEvent()->unsEvent();
                break;
            }
        }

    }
}
