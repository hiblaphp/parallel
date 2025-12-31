<?php

declare(strict_types=1);

namespace Hibla\Parallel;

/**
 * Represents the result of a settled task
 *
 * @template TValue
 */
final readonly class TaskResult
{
    private const string STATUS_FULFILLED = 'fulfilled';
    private const string STATUS_REJECTED = 'rejected';

    /**
     * @param string $status
     * @param TValue|null $value
     * @param string|null $reason
     */
    private function __construct(
        public string $status,
        public mixed $value = null,
        public ?string $reason = null
    ) {
    }

    /**
     * Create a fulfilled result
     *
     * @template TFulfilledValue
     * @param TFulfilledValue $value
     * @return self<TFulfilledValue>
     */
    public static function fulfilled(mixed $value): self
    {
        /** @var self<TFulfilledValue> */
        return new self(self::STATUS_FULFILLED, $value);
    }

    /**
     * Create a rejected result
     *
     * @return self<never>
     */
    public static function rejected(string $reason): self
    {
        /** @var self<never> */
        return new self(self::STATUS_REJECTED, null, $reason);
    }

    /**
     * Check if the result is fulfilled
     *
     * @return bool
     */
    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    /**
     * Check if the result is rejected
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Get the value if fulfilled, throw exception if rejected
     *
     * @return TValue
     * @throws \RuntimeException If result is rejected
     */
    public function getValue(): mixed
    {
        if ($this->isRejected()) {
            throw new \RuntimeException("Cannot get value from rejected result: {$this->reason}");
        }

        /** @var TValue */
        return $this->value;
    }

    /**
     * Get the reason if rejected, null otherwise
     *
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Convert to array representation
     *
     * @return array{status: 'fulfilled', value: TValue}|array{status: 'rejected', reason: string}
     */
    public function toArray(): array
    {
        if ($this->isFulfilled()) {
            /** @var TValue $value */
            $value = $this->value;
            return [
                'status' => self::STATUS_FULFILLED,
                'value' => $value,
            ];
        }

        return [
            'status' => self::STATUS_REJECTED,
            'reason' => $this->reason ?? 'Unknown error',
        ];
    }
}