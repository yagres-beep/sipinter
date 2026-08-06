<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Tidak dapat mengubah status dokumen dari \"{$from}\" ke \"{$to}\".");
    }
}
