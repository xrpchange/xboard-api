<?php

namespace App\Exceptions;

class InvalidTokenException extends XBoardException
{
    public function __construct(string $message = 'Invalid token')
    {
        parent::__construct($message, 'INVALID_TOKEN', 401);
    }
}







