# Adexos Kafka Connector

This module is a bridge of [koco/messenger-kafka](https://github.com/KonstantinCodes/messenger-kafka/tree/master) for
Magento 2.

It is built to use Magento 2 native queue system with some adjustments to the settings.

It also handles reading with Avro Schemes.

The connector supports consuming and publishing Avro messages using Magento's native queue interfaces.

## Installation

```bash
composer require adexos/m2-kafka-connector
```

## Usage

### Declare your configuration

To do so, you can simply include in your `app/code/Namespace/Module/etc/adminhtml/system.xml` the kafka configuration
form :

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Config:etc/system_file.xsd">
    <system>
        <section id="kafka" translate="label" type="text" sortOrder="300" showInDefault="1">
            <group id="warehouse" translate="" type="text" sortOrder="20" showInDefault="1">
                <label>Warehouse</label>
                <include path="Adexos_KafkaConnector::includes/kafka_conf_included.xml"/>
            </group>
        </section>
    </system>
</config>
```

Please note the `group id` you set, it will be used to connect the queue runner to the Kafka broker

You can find the configuration here : `Stores -> Configuration -> Services -> Kafka`

### Add the queue system

#### Communication

`app/code/Namespace/Module/etc/communication.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Communication/etc/communication.xsd">
    <topic name="warehouse.update.stock" request="Namespace\Module\Model\StockMessage"/>
</config>
```

The `request` field will be used as the model of the message you are receiving.

This class **must be full typed with PhpDoc** because of Magento 2 requirements

> Please note that the `topic name` DO NOT HAVE to be the same as the Kafka queue you are looking for.
> Since most of Kafka queues are in the same broker but have different names depending on the environment, we cannot
> define them in .xml as we do for other connection types like `db` queues
>
> Instead, the real Kafka topic name must be defined in the system you have set earlier.**

#### Queue consumer

`app/code/Namespace/Module/etc/queue_consumer.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/consumer.xsd">
    <consumer name="warehouse"
              queue="warehouse.stock.update"
              connection="kafka.warehouse"
              handler="Namespace\Module\Handler\StockMessageHandler::handle"
              onlySpawnWhenMessageAvailable="0"
    />
</config>
```

- `queue` : as per Magento doc, it must be identical to the topic name defined in the `communication.xml` file
- `connection` : please note that the Kafka connection **must** starts with `kafka.`, for example : `kafka.warehouse`.
  This is done to detect **all** kafka connection types and to retrieve them in the configuration defined.

> If connection is `kafka.warehouse`, the group id defined in the system.xml file but be `warehouse`. This allow us to
> map through the `core_config_data` table automatically
>

- `handler` : The handler that will take your message and process it. The parameter type must be as same type as the one
  defined in `communication.xml` inside the `request` field
- `onlySpawnWhenMessageAvailable` : this flag must be set to zero. Since natively Magento only spawns a consumer when
  there is a message available, the Kafka consumer will be spawned and despawned endlessly. A Kafka consumer only
  commits its offset when a message is read. However, if no message is read and no offset is committed yet, the next
  time the consumer will spawn, it'll ready from the very end of the queue. Adding this flag ensure that a message will
  be read at least once and the offset will be commited. In fact, you can remove it after the offset is commit (manually
  or automatically). It is not advised to do so.

#### High performance queue

You might need to have a better performance for your queue. To do so, you can add leverage the power of headers from
Kafka message if your messages has some.
If you can filter only needed messages by reading headers, you can just skip the Avro deserialization and avoid a lot of
processing time.

Here is how you can do it :

`app/code/Namespace/Module/etc/di.xml`

```xml

<type name="Adexos\KafkaConnector\Serializer\AvroSchemaRegistrySerializer">
    <arguments>
        <argument name="headerFilters" xsi:type="array">
            <item name="your.magento.topic.name" xsi:type="object">
                Namespace\Module\Serializer\HeaderFilter\YourCustomAvroHeaderFilter
            </item>
        </argument>
    </arguments>
</type>
```

`your.magento.topic.name` : must be replaced by the topic name you defined in `communication.xml` file.
`Namespace\Module\Serializer\HeaderFilter\YourCustomAvroHeaderFilter` : must be replaced by your custom header filter

Your custom header filter must implement the `Adexos\KafkaConnector\Serializer\HeaderFilter\AvroHeaderFilterInterface`
and the serializer will automatically pass the headers to your filter if it exists for your topic.

## Publishing

Declare the payload DTO in `etc/communication.xml`, just as for consumption:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Communication/etc/communication.xsd">
    <topic name="orders.create" request="Namespace\Module\Model\OrderMessage"/>
</config>
```

Declare `etc/queue_publisher.xml` in the module using the connector:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/publisher.xsd">
    <publisher topic="orders.create">
        <connection name="kafka.orders" exchange="kafka"/>
    </publisher>
</config>
```

Add a Kafka configuration group with ID `orders` using the same configuration include as above.
No consumer or AMQP topology is required for publication. The exchange routes the Magento topic directly to
the physical Kafka topic configured under `kafka/orders/general/topic_name`.

Inject `Magento\Framework\MessageQueue\PublisherInterface` and publish the DTO:

```php
$this->publisher->publish('orders.create', $orderMessage);
```

The DTO is serialized to JSON using the connector's Symfony serializer, then encoded as Avro using
the registered writer schema. DTO properties must match the Avro contract, including nested records.
Kafka publication uses the following settings under `kafka/orders/`:

| Path | Purpose |
| --- | --- |
| `general/dsn` | Broker host(s) and port(s), without a URL scheme or path |
| `general/topic_name` | Physical Kafka topic to publish to |
| `general/publish_timeout_ms` | Koco producer flush timeout, default `10000` ms |
| `general/group_id` | Group ID from the shared Kafka transport configuration |
| `auth/security_protocol` | For example `SASL_SSL` |
| `auth/sasl_mechanism` | SASL mechanism assigned to the service account |
| `auth/username`, `auth/password` | Kafka service account credentials |
| `avro/schema_registry_url` | Schema Registry URL |
| `avro/username`, `avro/password` | Schema Registry credentials |
| `avro/subject` | Existing writer subject; defaults to `<physical-topic>-value` |
| `avro/schema_version` | Positive version to pin a contract; empty or `0` selects latest |

Publication uses the same connection configuration and Koco transport as consumption, including the group ID,
authentication and debug settings. Consumer offset settings and header filters apply only to consumption.
Writer schemas are cached for the lifetime of the queue instance. Restart long-running publishers when
changing a contract that uses the latest schema. The connector never registers a schema automatically.
The service account must have Kafka write permissions and Schema Registry read/lookup permissions.

### Keys and headers

For partition keys and Kafka headers, use the Magento queue interface directly:

```php
// Inject Magento\Framework\MessageQueue\QueueFactoryInterface as $queueFactory.
$queue = $queueFactory->create('orders.create', 'kafka.orders');
$queue->push(new \Magento\Framework\MessageQueue\Envelope(
    json_encode($record, JSON_THROW_ON_ERROR),
    [
        'kafka_key' => 'order-00123',
        'kafka_headers' => ['eventType' => 'CreateCustomerOrder']
    ]
));
```

The body must be a JSON object containing the Avro record. Keys must be strings; header names must be strings
and header values must be strings or null. Metadata is sent separately from the Avro payload.
Use the same key for events belonging to the same order when partition ordering is needed.

### Delivery and failures

Publication delegates to Koco's sender through the same cached transport used for consumption.
The sender flushes the producer using the configured timeout. Serialization and transport exceptions
propagate to the caller.

The connector does not provide an outbox, business event deduplication or background retries.
Those belong in the application publishing the events. Consumer dev mode does not disable publication.
