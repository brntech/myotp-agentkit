<?php

namespace App\Exceptions;

use RuntimeException;

class MyOtpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }
}
