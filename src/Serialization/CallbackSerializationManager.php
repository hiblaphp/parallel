<?php

namespace Hibla\Parallel\Serialization;

/**
 * Main serialization manager that coordinates different serializers
 */
class CallbackSerializationManager
{
    /**
     * @var CallbackSerializerInterface[] Array of serializers
     */
    private array $serializers = [];

    /**
     * @var ContextSerializer Context data serializer
     */
    private ContextSerializer $contextSerializer;

    public function __construct()
    {
        $this->contextSerializer = new ContextSerializer();
        $this->registerDefaultSerializers();
    }

    /**
     * Register default serializers
     */
    private function registerDefaultSerializers(): void
    {
        $this->addSerializer(new StringFunctionSerializer());
        $this->addSerializer(new StaticMethodSerializer());
        $this->addSerializer(new InstanceMethodSerializer());
        $this->addSerializer(new ClosureSerializer());
        $this->addSerializer(new InvokableObjectSerializer());
        $this->addSerializer(new FallbackClosureSerializer());
    }

    /**
     * Add a serializer to the manager
     *
     * @param CallbackSerializerInterface $serializer The serializer to add
     */
    public function addSerializer(CallbackSerializerInterface $serializer): void
    {
        $this->serializers[] = $serializer;
        
        // Sort by priority (highest first)
        usort($this->serializers, function ($a, $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    /**
     * Serialize a callback using the best available serializer
     *
     * @param callable $callback The callback to serialize
     * @return string PHP code that recreates the callback
     * @throws SerializationException If no serializer can handle the callback
     */
    public function serializeCallback(callable $callback): string
    {
        foreach ($this->serializers as $serializer) {
            if ($serializer->canSerialize($callback)) {
                try {
                    return $serializer->serialize($callback);
                } catch (SerializationException $e) {
                    continue;
                }
            }
        }

        throw new SerializationException('No serializer found for callback type: ' . $this->getCallableType($callback));
    }

    /**
     * Serialize context data
     *
     * @param array $context The context data to serialize
     * @return string PHP code that recreates the context
     * @throws SerializationException If serialization fails
     */
    public function serializeContext(array $context): string
    {
        return $this->contextSerializer->serialize($context);
    }

    /**
     * Check if a callback can be serialized
     *
     * @param callable $callback The callback to check
     * @return bool True if callback can be serialized
     */
    public function canSerializeCallback(callable $callback): bool
    {
        foreach ($this->serializers as $serializer) {
            if ($serializer->canSerialize($callback)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if context can be serialized
     *
     * @param array $context The context to check
     * @return bool True if context can be serialized
     */
    public function canSerializeContext(array $context): bool
    {
        return $this->contextSerializer->canSerialize($context);
    }

    /**
     * Get information about available serializers
     *
     * @return array Serializer information
     */
    public function getSerializerInfo(): array
    {
        $info = [];
        
        foreach ($this->serializers as $serializer) {
            $className = get_class($serializer);
            $info[] = [
                'class' => $className,
                'name' => basename(str_replace('\\', '/', $className)),
                'priority' => $serializer->getPriority(),
            ];
        }
        
        return $info;
    }

    /**
     * Get the callable type as a string
     *
     * @param callable $callback The callable to analyze
     * @return string Human-readable callable type
     */
    private function getCallableType(callable $callback): string
    {
        if (is_string($callback)) {
            return 'string function';
        }
        
        if (is_array($callback)) {
            [$class, $method] = $callback;
            if (is_string($class)) {
                return 'static method';
            }
            return 'instance method';
        }
        
        if ($callback instanceof \Closure) {
            return 'closure';
        }
        
        if (is_object($callback)) {
            return 'invokable object';
        }
        
        return 'unknown';
    }
}