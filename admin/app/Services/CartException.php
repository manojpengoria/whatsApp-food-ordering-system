<?php

namespace App\Services;

use RuntimeException;

/** A business-rule failure the bot can show to the customer. */
class CartException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
