<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

pest()->beforeAll(function () {
    Promise::setRejectionHandler(fn () => null);
});
