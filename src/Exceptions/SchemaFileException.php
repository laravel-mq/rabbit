<?php

namespace LaravelMq\Rabbit\Exceptions;

use Exception;

class SchemaFileException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
