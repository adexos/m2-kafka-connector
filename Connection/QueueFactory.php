<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Connection;

use Adexos\KafkaConnector\Model\Config\ConfigResolverFactory;
use Magento\Framework\MessageQueue\QueueFactoryInterface;
use Magento\Framework\MessageQueue\QueueInterface;

class QueueFactory implements QueueFactoryInterface
{
    public function __construct(
        private readonly Queue\QueueFactory $queueFactory,
        private readonly ConfigResolverFactory $configResolverFactory
    ) {
    }

    public function create($queueName, $connectionName): QueueInterface
    {
        $kafkaConfig = $this->configResolverFactory->create(['configurationName' => explode('.', $connectionName)[1]]);

        return $this->queueFactory->create([
            'kafkaConfig'      => $kafkaConfig,
            'magentoTopicName' => $queueName
        ]);
    }
}
