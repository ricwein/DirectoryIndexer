<?php

namespace ricwein\Indexer\Exception;

use Throwable;
use UnexpectedValueException;

class NotFoundException extends UnexpectedValueException
{
    public function __construct($message = "File Not Found", $code = 404, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
