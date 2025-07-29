# Adexos Kafka Connector

This module is a bridge of [koco/messenger-kafka](https://github.com/KonstantinCodes/messenger-kafka/tree/master) for
Magento 2.

It is built to use Magento 2 native queue system with some adjustments to the settings.

It also handles reading with Avro Schemes.

## Disclaimer

Only the reading part is done from now. You can't write to a queue.

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