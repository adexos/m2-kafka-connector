<?php

declare(strict_types=1);

namespace Adexos\KafkaConnector\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;

class ConfigResolver
{
    private const KAFKA_GENERAL_DSN_XML_PATH = 'kafka/%s/general/dsn';
    private const KAFKA_GENERAL_GROUP_ID_XML_PATH = 'kafka/%s/general/group_id';
    private const KAFKA_GENERAL_TOPIC_NAME_XML_PATH = 'kafka/%s/general/topic_name';
    private const KAFKA_GENERAL_DEBUG_XML_PATH = 'kafka/%s/general/debug';
    private const KAFKA_GENERAL_DEV_XML_PATH = 'kafka/%s/general/dev';
    private const KAFKA_GENERAL_READ_FROM_START_XML_PATH = 'kafka/%s/general/read_from_start';
    private const KAFKA_AUTH_SASL_MECHANISM_XML_PATH = 'kafka/%s/auth/sasl_mechanism';
    private const KAFKA_AUTH_SECURITY_PROTOCOL_XML_PATH = 'kafka/%s/auth/security_protocol';
    private const KAFKA_AUTH_USERNAME_XML_PATH = 'kafka/%s/auth/username';
    private const KAFKA_AUTH_PASSWORD_XML_PATH = 'kafka/%s/auth/password';
    private const KAFKA_AVRO_SCHEMA_REGISTRY_URL_XML_PATH = 'kafka/%s/avro/schema_registry_url';
    private const KAFKA_AVRO_USERNAME_XML_PATH = 'kafka/%s/avro/username';
    private const KAFKA_AVRO_PASSWORD_XML_PATH = 'kafka/%s/avro/password';

    public function __construct(
        private readonly string $configurationName,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getDsn(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_GENERAL_DSN_XML_PATH));
    }

    public function getTopicName(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_GENERAL_TOPIC_NAME_XML_PATH));
    }

    public function getGroupId(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_GENERAL_GROUP_ID_XML_PATH));
    }

    public function isDebug(): bool
    {
        return $this->scopeConfig->isSetFlag($this->buildConfigPath(self::KAFKA_GENERAL_DEBUG_XML_PATH));
    }

    public function isDev(): bool
    {
        return $this->scopeConfig->isSetFlag($this->buildConfigPath(self::KAFKA_GENERAL_DEV_XML_PATH));
    }

    public function readFromStart(): bool
    {
        return $this->scopeConfig->isSetFlag($this->buildConfigPath(self::KAFKA_GENERAL_READ_FROM_START_XML_PATH));
    }

    public function getSaslMechanism(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_AUTH_SASL_MECHANISM_XML_PATH));
    }

    public function getSecurityProtocol(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_AUTH_SECURITY_PROTOCOL_XML_PATH));
    }

    public function getAuthUsername(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_AUTH_USERNAME_XML_PATH));
    }

    public function getAuthPassword(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_AUTH_PASSWORD_XML_PATH));
    }

    public function getAvroSchemaRegistryUrl(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_AVRO_SCHEMA_REGISTRY_URL_XML_PATH));
    }

    public function getAvroUsername(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_AVRO_USERNAME_XML_PATH));
    }

    public function getAvroPassword(): string
    {
        return $this->scopeConfig->getValue($this->buildConfigPath(self::KAFKA_AVRO_PASSWORD_XML_PATH));
    }

    private function buildConfigPath(string $node): string
    {
        return sprintf($node, $this->configurationName);
    }
}
