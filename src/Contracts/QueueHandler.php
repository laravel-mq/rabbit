<?php

namespace LaravelMq\Rabbit\Contracts;

use PhpAmqpLib\Message\AMQPMessage;

interface QueueHandler
{
    public function queue(): string;

    public function schemaPath(): ?string;

    public function handle(AMQPMessage $message): void;
}
