<?php

namespace App\Exceptions;

use RuntimeException;

class SyncInProgressException extends RuntimeException
{
    public function __construct(string $message = 'A sync is already in progress. Please try again later.')
    {
        parent::__construct($message);
    }
}
