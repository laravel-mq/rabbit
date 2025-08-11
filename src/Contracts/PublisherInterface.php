<?php

namespace LaravelMq\Rabbit\Contracts;

interface PublisherInterface
{
    public function publish(string $queueOrExchange, array $payload, ?string $routingKey = null, ?string $schemaPath = null): void;
}
