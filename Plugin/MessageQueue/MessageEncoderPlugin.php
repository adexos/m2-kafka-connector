<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Plugin\MessageQueue;

use Adexos\KafkaConnector\Connection\ConnectionTypeResolver;
use Magento\Framework\Communication\ConfigInterface as CommunicationConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;
use Magento\Framework\MessageQueue\MessageEncoder;
use Magento\Framework\MessageQueue\Publisher\ConfigInterface as PublisherConfigInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

class MessageEncoderPlugin
{
    public function __construct(
        private readonly ConsumerConfigInterface $consumerConfig,
        private readonly CommunicationConfigInterface $communicationConfig,
        private readonly SerializerInterface $serializer,
        private readonly ConnectionTypeResolver $connectionTypeResolver,
        private readonly PublisherConfigInterface $publisherConfig
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundEncode(
        MessageEncoder $subject,
        callable $proceed,
        string $topic,
        $message,
        bool $requestType = true
    ): string {
        if (!$requestType || !$this->isKafkaPublisher($topic)) {
            return $proceed($topic, $message, $requestType);
        }

        return $this->serializer->serialize($message, JsonEncoder::FORMAT);
    }

    private function isKafkaPublisher(string $topic): bool
    {
        try {
            $publisher = $this->publisherConfig->getPublisher($topic);
        } catch (LocalizedException) {
            return false;
        }

        return !$publisher->isDisabled()
            && $this->connectionTypeResolver->getConnectionType($publisher->getConnection()->getName()) === 'kafka';
    }

    /**
     * @throws LocalizedException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDecode(
        MessageEncoder $subject,
        callable $proceed,
        string $topic,
        $message,
        bool $requestType = true
    ) {
        if ($this->isKafkaTopic($topic)) {
            $communication = $this->communicationConfig->getTopic($topic);

            return $this->serializer->deserialize(
                $message,
                $communication[CommunicationConfigInterface::TOPIC_REQUEST],
                JsonEncoder::FORMAT
            );
        }

        return $proceed($topic, $message, $requestType);
    }

    private function isKafkaTopic(string $topic): bool
    {
        foreach ($this->consumerConfig->getConsumers() as $consumer) {
            $isKafkaConnection = $this->connectionTypeResolver->getConnectionType($consumer->getConnection());

            if ($isKafkaConnection && $consumer->getQueue() === $topic) {
                return true;
            }
        }

        return false;
    }
}
