<?php

declare(strict_types=1);

namespace PigIphoneUse;

use RuntimeException;

/** An error WDA itself reported: `wdaCode` is its W3C error name (`no such element`, `invalid session id` …). */
final class WdaError extends RuntimeException
{
    public function __construct(
        public readonly string $wdaCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
