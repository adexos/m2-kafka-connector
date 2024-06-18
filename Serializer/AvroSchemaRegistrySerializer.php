<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Serializer;

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

    /**
     * @throws AvroIOException
     */
    public function __construct(string $baseUri, string $userAuth, string $passwordAuth)
    {
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
    }

    /**
     * @throws SchemaRegistryException
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $result = $this->recordSerializer->decodeMessage($encodedEnvelope['body']);

        return new Envelope((object) $result);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function encode(Envelope $envelope): array
    {
        return [];
    }
}
