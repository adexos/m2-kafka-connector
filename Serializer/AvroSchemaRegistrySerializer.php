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
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function FlixTech\AvroSerializer\Protocol\encoder;
use function FlixTech\AvroSerializer\Serialize\avroDatumWriter;
use function Widmogrod\Functional\identity;
use function Widmogrod\Functional\reThrow;

class AvroSchemaRegistrySerializer implements SerializerInterface
{
    private RecordSerializer $recordSerializer;
    private string $topic;
    private Registry $registry;
    private ?AvroSchema $writerSchema = null;
    private ?int $writerSchemaId = null;

    /** @var callable */
    private $datumWriter;

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
        private readonly int $schemaVersion = 0,
        ?int $requestTimeoutMs = null
    ) {
        $schemaRegistryClient = new CachedRegistry(
            new PromisingRegistry(
                new Client(
                    [
                        'base_uri' => $baseUri,
                        'auth'     => [$userAuth, $passwordAuth],
                        'connect_timeout' => $requestTimeoutMs === null ? 0 : max(1, $requestTimeoutMs) / 1000,
                        'timeout'         => $requestTimeoutMs === null ? 0 : max(1, $requestTimeoutMs) / 1000
                    ]
                )
            ),
            new AvroObjectCacheAdapter()
        );

        $this->recordSerializer = new RecordSerializer($schemaRegistryClient, []);
        $this->datumWriter = avroDatumWriter();
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

        if ($this->writerSchemaId === null) {
            $schemaId = $this->registry->schemaId($this->subject, $this->writerSchema);
            $this->writerSchemaId = $schemaId instanceof PromiseInterface ? $schemaId->wait() : $schemaId;
        }

        // Registry schema IDs are only unique within that Registry, never across connections.
        $record = ($this->datumWriter)($this->writerSchema, (array) $envelope->getMessage());

        return ['body' => $record->bind(encoder(0)($this->writerSchemaId))->either(reThrow(...), identity(...))];
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
