<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Serializer;

use Adexos\KafkaConnector\Serializer\HeaderFilter\AvroHeaderFilterInterface;
use AvroIOException;
use FlixTech\AvroSerializer\Objects\RecordSerializer;
use FlixTech\SchemaRegistryApi\Exception\SchemaRegistryException;
use FlixTech\SchemaRegistryApi\Registry\Cache\AvroObjectCacheAdapter;
use FlixTech\SchemaRegistryApi\Registry\CachedRegistry;
use FlixTech\SchemaRegistryApi\Registry\PromisingRegistry;
use GuzzleHttp\Client;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AvroSchemaRegistrySerializer implements SerializerInterface
{
    private RecordSerializer $recordSerializer;
    private string $topic;

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
        array $headerFilters = []
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

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function encode(Envelope $envelope): array
    {
        return [];
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
