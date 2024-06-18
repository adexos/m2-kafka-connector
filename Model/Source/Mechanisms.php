<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Mechanisms implements OptionSourceInterface
{
    private const SASL_MECHANISM_PLAIN = 'PLAIN';

    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::SASL_MECHANISM_PLAIN,
                'label' => self::SASL_MECHANISM_PLAIN
            ]
        ];
    }
}
