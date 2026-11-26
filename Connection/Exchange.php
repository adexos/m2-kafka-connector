<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Connection;

use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\ExchangeInterface;
use Magento\Framework\MessageQueue\QueueInterface;

class Exchange implements ExchangeInterface
{
    /** @var QueueInterface[] */
    private array $queues = [];

    public function __construct(
        private readonly QueueFactory $queueFactory,
        private readonly string $connectionName
    ) {
    }

    public function enqueue($topic, EnvelopeInterface $envelope): void
    {
        $this->queues[$topic] ??= $this->queueFactory->create($topic, $this->connectionName);

        $this->queues[$topic]->push($envelope);
    }
}
