<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Plugin\MessageQueue;

use Adexos\KafkaConnector\Connection\ConnectionTypeResolver;
use Magento\Framework\Communication\ConfigInterface as CommunicationConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;
use Magento\Framework\MessageQueue\MessageEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

class MessageEncoderPlugin
{
    public function __construct(
        private readonly ConsumerConfigInterface $consumerConfig,
        private readonly CommunicationConfigInterface $communicationConfig,
        private readonly SerializerInterface $serializer,
        private readonly ConnectionTypeResolver $connectionTypeResolver
    ) {
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
