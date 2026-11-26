<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Connection;

use Adexos\KafkaConnector\Model\Config\ConfigResolver;
use InvalidArgumentException;
use Koco\Kafka\RdKafka\RdKafkaFactory;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use RdKafka\Conf;
use RdKafka\Message;
use RdKafka\Producer as KafkaProducer;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class Producer
{
    private ?KafkaProducer $producer = null;
    private bool $delivered = false;
    private ?int $deliveryError = null;

    public function __construct(
        private readonly ConfigResolver $kafkaConfig,
        private readonly SerializerInterface $serializer,
        private readonly RdKafkaFactory $rdKafkaFactory
    ) {
    }

    public function send(EnvelopeInterface $envelope): void
    {
        $payload = $this->encode($envelope);
        $properties = $envelope->getProperties();
        $key = $this->getKey($properties);
        $headers = $this->getHeaders($properties);

        $this->delivered = false;
        $this->deliveryError = null;

        $producer = $this->getProducer();
        $confirmed = false;

        try {
            $topic = $producer->newTopic($this->kafkaConfig->getTopicName());
            $topic->producev(RD_KAFKA_PARTITION_UA, 0, $payload, $key, $headers);
            $producer->poll(0);

            $result = $producer->flush($this->kafkaConfig->getPublishTimeoutMs());

            if (
                $result !== RD_KAFKA_RESP_ERR_NO_ERROR
                || !$this->delivered
                || $this->deliveryError !== RD_KAFKA_RESP_ERR_NO_ERROR
            ) {
                throw new RuntimeException(sprintf(
                    'Kafka publication failed for topic "%s" (flush: %d, delivery: %s).',
                    $this->kafkaConfig->getTopicName(),
                    $result,
                    $this->deliveryError === null ? 'unconfirmed' : (string) $this->deliveryError
                ));
            }

            $confirmed = true;
        } finally {
            if (!$confirmed) {
                // A failed call must not leave messages queued for a later send.
                $producer->purge(RD_KAFKA_PURGE_F_QUEUE | RD_KAFKA_PURGE_F_INFLIGHT);
                $producer->poll(0);

                $this->producer = null;
            }
        }
    }

    private function encode(EnvelopeInterface $envelope): string
    {
        $record = json_decode($envelope->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($record) || !str_starts_with(ltrim($envelope->getBody()), '{')) {
            throw new InvalidArgumentException('Kafka message body must be a JSON object.');
        }

        return $this->serializer->encode(new Envelope((object) $record))['body'];
    }

    private function getKey(array $properties): ?string
    {
        $key = $properties['kafka_key'] ?? null;

        if ($key !== null && !is_string($key)) {
            throw new InvalidArgumentException('Kafka message key must be a string.');
        }

        return $key;
    }

    private function getHeaders(array $properties): array
    {
        $headers = $properties['kafka_headers'] ?? [];

        if (!is_array($headers)) {
            throw new InvalidArgumentException('Kafka message headers must be an array.');
        }

        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && $value !== null)) {
                throw new InvalidArgumentException('Kafka headers must have string names and string or null values.');
            }
        }

        return $headers;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function getProducer(): KafkaProducer
    {
        if ($this->producer !== null) {
            return $this->producer;
        }

        $conf = new Conf();
        $conf->set('bootstrap.servers', $this->kafkaConfig->getDsn());
        $protocol = $this->kafkaConfig->getSecurityProtocol();
        $conf->set('security.protocol', $protocol);

        if (str_starts_with($protocol, 'SASL')) {
            $conf->set('sasl.mechanism', $this->kafkaConfig->getSaslMechanism());
            $conf->set('sasl.username', $this->kafkaConfig->getAuthUsername());
            $conf->set('sasl.password', $this->kafkaConfig->getAuthPassword());
        }

        $conf->set('enable.idempotence', 'true');
        $conf->set('acks', 'all');
        $conf->set('message.timeout.ms', (string) $this->kafkaConfig->getPublishTimeoutMs());

        $conf->setDrMsgCb(function (KafkaProducer $producer, Message $message): void {
            $this->delivered = true;
            $this->deliveryError = $message->err;
        });

        return $this->producer = $this->rdKafkaFactory->createProducer($conf);
    }
}
