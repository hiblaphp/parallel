<?php

declare(strict_types=1);

use function Hibla\await;
use function Hibla\parallel;

use Tests\Fixtures\TestValueObject;

describe('Complex Serialization Integration', function () {
    it('can return a simple Value Object from a parallel task', function () {
        $input = new TestValueObject(
            id: 'user-123',
            secret: 'hidden-api-key',
            metadata: ['role' => 'admin']
        );

        $result = await(parallel(fn () => $input));

        expect($result)->toBeInstanceOf(TestValueObject::class);
        expect($result->id)->toBe('user-123');
        expect($result->getSecret())->toBe('hidden-api-key');
        expect($result->metadata)->toBe(['role' => 'admin']);
    });

    it('can return nested Value Objects (Object Graph)', function () {
        $parent = new TestValueObject('parent', 's1');
        $child = new TestValueObject('child', 's2');
        $parent->setChild($child);

        $result = await(parallel(fn () => $parent));

        expect($result)->toBeInstanceOf(TestValueObject::class);
        expect($result->id)->toBe('parent');

        expect($result->getChild())->toBeInstanceOf(TestValueObject::class);
        expect($result->getChild()->id)->toBe('child');
        expect($result->getChild()->getSecret())->toBe('s2');
    });

    it('can return a collection of Value Objects', function () {
        $collection = [
            new TestValueObject('A', 'sA'),
            new TestValueObject('B', 'sB'),
            new TestValueObject('C', 'sC'),
        ];

        $result = await(parallel(fn () => $collection));

        expect($result)->toBeArray();
        expect($result)->toHaveCount(3);

        expect($result[0])->toBeInstanceOf(TestValueObject::class);
        expect($result[0]->id)->toBe('A');

        expect($result[2]->id)->toBe('C');
    });

    it('preserves object identity within the payload scope', function () {
        $shared = new TestValueObject('shared', 'secret');
        $wrapper = ['a' => $shared, 'b' => $shared];

        $result = await(parallel(fn () => $wrapper));

        expect($result['a'])->toBeInstanceOf(TestValueObject::class);
        expect($result['a'])->toBe($result['b']);
    });
});
