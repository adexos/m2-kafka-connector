<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Serializer\HeaderFilter;

interface AvroHeaderFilterInterface
{
    public function isValid(array $headers): bool;
}
