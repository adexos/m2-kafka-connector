<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Connection\Queue;

use Adexos\KafkaConnector\Model\Config\ConfigResolver;
use Adexos\KafkaConnector\Serializer\AvroSchemaRegistrySerializerFactory;
use Exception;
use JsonException;
use Koco\Kafka\Messenger\KafkaMessageStamp;
use Koco\Kafka\Messenger\KafkaTransportFactory;
use Koco\Kafka\RdKafka\RdKafkaFactory;
use LogicException;
use Magento\Framework\MessageQueue\Envelope;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\QueueInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function json_encode;

class Queue implements QueueInterface
{
    private const ORIGINAL_ENVELOPE = 'original_envelope';

    public function __construct(
        private readonly LoggerInterface                     $logger,
        private readonly AvroSchemaRegistrySerializerFactory $avroSchemaRegistrySerializerFactory,
        private readonly ConfigResolver                      $kafkaConfig,
        private readonly string                              $magentoTopicName
    )
    {
    }

    private ?TransportInterface $transport = null;

    /**
     * @throws JsonException
     */
    public function dequeue(): ?Envelope
    {
        $lastStamp = $this->getKafkaConnection()->get();

        $envelope = current($lastStamp);

        if ($envelope === false) {
            return null;
        }

        /** @var KafkaMessageStamp $lastStamp */
        $lastStamp = $envelope->last(KafkaMessageStamp::class);

        $message = $lastStamp->getMessage();

        $id = sprintf('%d-%d', $message->partition, $message->offset);

        return new Envelope(
            json_encode($envelope->getMessage(), JSON_THROW_ON_ERROR),
            ['message_id' => $id, 'topic_name' => $this->magentoTopicName, self::ORIGINAL_ENVELOPE => $envelope]
        );
    }

    public function acknowledge(EnvelopeInterface $envelope): void
    {
        if (!$this->kafkaConfig->isDev()) {
            $this->getKafkaConnection()->ack($envelope->getProperties()[self::ORIGINAL_ENVELOPE]);
        }
    }

    public function subscribe($callback): void
    {
        while (true) {
            while ($envelope = $this->dequeue()) {
                try {
                    $callback($envelope);
                } catch (Exception $e) {
                    $this->logger->error(
                        $e,
                        ['context' => 'adexos-kafka', 'state' => 'reading', 'topic' => $this->kafkaConfig->getTopicName(), 'exception' => $e]
                    );

                    $this->reject($envelope, true, $e->getMessage());
                }
            }
        }
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function reject(EnvelopeInterface $envelope, $requeue = true, $rejectionMessage = null): void
    {
        $this->logger->error(
            $rejectionMessage,
            ['context' => 'adexos-kafka', 'state' => 'rejected', 'topic' => $this->kafkaConfig->getTopicName()]
        );

        $this->getKafkaConnection()->reject($envelope->getProperties()[self::ORIGINAL_ENVELOPE]);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function push(EnvelopeInterface $envelope): void
    {
        throw new LogicException('Method not implemented yet');
    }

    private function getKafkaConnection(): TransportInterface
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        $rdKafkaFactory = new RdKafkaFactory();

        $kafkaTransportFactory = new KafkaTransportFactory($rdKafkaFactory, $this->logger);

        $avroSerializer = $this->avroSchemaRegistrySerializerFactory->create([
            'baseUri' => $this->kafkaConfig->getAvroSchemaRegistryUrl(),
            'userAuth' => $this->kafkaConfig->getAvroUsername(),
            'passwordAuth' => $this->kafkaConfig->getAvroPassword()
        ]);

        $this->transport = $kafkaTransportFactory->createTransport(
            sprintf('kafka+ssl://%s', $this->kafkaConfig->getDsn()),
            [
                'topic' => ['name' => $this->kafkaConfig->getTopicName()],
                'kafka_conf' => array_merge(
                    [
                        'group.id' => $this->kafkaConfig->getGroupId(),
                        'sasl.mechanism' => $this->kafkaConfig->getSaslMechanism(),
                        'security.protocol' => $this->kafkaConfig->getSecurityProtocol(),
                        'sasl.username' => $this->kafkaConfig->getAuthUsername(),
                        'sasl.password' => $this->kafkaConfig->getAuthPassword(),
                        'enable.auto.offset.store' => 'false',
                        'enable.auto.commit' => 'false'
                    ],
                    $this->getDebugSubconfig(),
                    $this->getDevSubconfig()
                )
            ],
            $avroSerializer
        );

        return $this->transport;
    }

    private function getDebugSubconfig(): array
    {
        if ($this->kafkaConfig->isDebug()) {
            return [
                'debug' => 'all'
            ];
        }

        return [];
    }

    private function getDevSubconfig(): array
    {
        if ($this->kafkaConfig->readFromStart()) {
            return [
                'auto.offset.reset' => 'beginning'
            ];
        }

        return [];
    }
}
