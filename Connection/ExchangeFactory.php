<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Connection;

use Magento\Framework\MessageQueue\ExchangeFactoryInterface;
use Magento\Framework\MessageQueue\ExchangeInterface;

class ExchangeFactory implements ExchangeFactoryInterface
{
    public function __construct(private readonly QueueFactory $queueFactory)
    {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function create($connectionName, array $data = []): ExchangeInterface
    {
        return new Exchange($this->queueFactory, $connectionName);
    }
}
