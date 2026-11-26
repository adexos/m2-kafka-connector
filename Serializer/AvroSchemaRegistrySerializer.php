<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Serializer;

use Adexos\KafkaConnector\Serializer\HeaderFilter\AvroHeaderFilterInterface;
use AvroIOException;
use AvroSchema;
use FlixTech\AvroSerializer\Objects\RecordSerializer;
use FlixTech\SchemaRegistryApi\Exception\SchemaRegistryException;
use FlixTech\SchemaRegistryApi\Registry;
use FlixTech\SchemaRegistryApi\Registry\Cache\AvroObjectCacheAdapter;
use FlixTech\SchemaRegistryApi\Registry\CachedRegistry;
use FlixTech\SchemaRegistryApi\Registry\PromisingRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AvroSchemaRegistrySerializer implements SerializerInterface
{
    private RecordSerializer $recordSerializer;
    private string $topic;
    private Registry $registry;
    private ?AvroSchema $writerSchema = null;

    /**
     * @var AvroHeaderFilterInterface[]
     */
    private array $headerFilters;

    /**
     * @throws AvroIOException
     */
    public function __construct(
        string $baseUri,
        string $userAuth,
        string $passwordAuth,
        string $topic,
        array $headerFilters = [],
        private readonly string $subject = '',
        private readonly int $schemaVersion = 0
    ) {
        $schemaRegistryClient = new CachedRegistry(
            new PromisingRegistry(
                new Client(
                    [
                        'base_uri' => $baseUri,
                        'auth'     => [$userAuth, $passwordAuth]
                    ]
                )
            ),
            new AvroObjectCacheAdapter()
        );

        $this->recordSerializer = new RecordSerializer($schemaRegistryClient, []);
        $this->registry = $schemaRegistryClient;
        $this->topic = $topic;
        $this->headerFilters = $headerFilters;
    }

    /**
     * @throws SchemaRegistryException
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        if (!$this->isValidHeader($encodedEnvelope['headers'] ?? [])) {
            return new Envelope((object)['message' => null]);
        }

        $result = $this->recordSerializer->decodeMessage($encodedEnvelope['body']);

        return new Envelope((object)$result);
    }

    public function encode(Envelope $envelope): array
    {
        if ($this->subject === '') {
            throw new LogicException('An Avro subject is required to publish a Kafka message.');
        }

        if ($this->writerSchema === null) {
            $schema = $this->schemaVersion > 0
                ? $this->registry->schemaForSubjectAndVersion($this->subject, $this->schemaVersion)
                : $this->registry->latestVersion($this->subject);

            $this->writerSchema = $schema instanceof PromiseInterface ? $schema->wait() : $schema;
        }

        $context = $envelope->last(SerializerStamp::class)?->getContext() ?? [];

        return [
            'body' => $this->recordSerializer->encodeRecord(
                $this->subject,
                $this->writerSchema,
                (array) $envelope->getMessage()
            ),
            'key' => $context['kafka_key'] ?? null,
            'headers' => $context['kafka_headers'] ?? []
        ];
    }

    private function isValidHeader(array $headers): bool
    {
        $targetHeaderFilter = $this->headerFilters[$this->topic] ?? null;

        if ($targetHeaderFilter === null) {
            return true;
        }

        return $targetHeaderFilter->isValid($headers);
    }
}
