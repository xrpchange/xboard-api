<?php

namespace App\Exceptions;

use Exception;

class XBoardException extends Exception
{
    protected string $errorCode;

    public function __construct(string $message, string $errorCode = 'INTERNAL_ERROR', int $code = 400)
    {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}







