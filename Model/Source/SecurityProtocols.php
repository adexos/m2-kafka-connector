<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SecurityProtocols implements OptionSourceInterface
{
    private const SECURITY_PROTOCOL_SASL_SSL = 'SASL_SSL';

    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::SECURITY_PROTOCOL_SASL_SSL,
                'label' => self::SECURITY_PROTOCOL_SASL_SSL
            ]
        ];
    }
}
