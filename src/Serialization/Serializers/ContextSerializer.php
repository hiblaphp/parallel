<?php

namespace Hibla\Parallel\Serialization\Serializers;

use Hibla\Parallel\Serialization\Exceptions\SerializationException;

/**
 * Handles serialization of context data using opis/closure
 */
class ContextSerializer
{
    /**
     * Serialize context data to PHP code
     *
     * @param array $context The context data to serialize
     * @return string PHP code that recreates the context
     * @throws SerializationException If serialization fails
     */
    public function serialize(array $context): string
    {
        if (empty($context)) {
            return '[]';
        }

        try {
            $serialized = serialize($context);
            return \sprintf('\\Opis\\Closure\\unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize context data: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Check if context can be serialized
     *
     * @param array $context The context to check
     * @return bool True if context can be serialized
     */
    public function canSerialize(array $context): bool
    {
        try {
            $this->serialize($context);
            return true;
        } catch (SerializationException $e) {
            return false;
        }
    }

    /**
     * Safe test of context serialization without throwing exceptions
     *
     * @param array $context The context to test
     * @return array Test result with details
     */
    public function testSerialization(array $context): array
    {
        $result = [
            'success' => false,
            'method' => 'opis/closure',
            'size' => 0,
            'errors' => [],
        ];

        try {
            $serialized = $this->serialize($context);
            $result['success'] = true;
            $result['size'] = \strlen($serialized);
        } catch (\Throwable $e) {
            $result['errors'][] = 'Serialization failed: ' . $e->getMessage();
        }

        return $result;
    }
}