<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Connection;

use Magento\Framework\MessageQueue\ConnectionTypeResolverInterface;

use function str_starts_with;

class ConnectionTypeResolver implements ConnectionTypeResolverInterface
{
    public function getConnectionType($connectionName): ?string
    {
        return str_starts_with($connectionName, 'kafka') ? 'kafka' : null;
    }
}
