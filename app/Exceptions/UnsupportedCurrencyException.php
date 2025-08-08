<?php

namespace App\Exceptions;

class UnsupportedCurrencyException extends XBoardException
{
    public function __construct(string $currency)
    {
        parent::__construct("Unsupported currency: {$currency}", 'UNSUPPORTED_CURRENCY', 400);
    }
}









