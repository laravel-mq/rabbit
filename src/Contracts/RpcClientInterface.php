<?php

namespace LaravelMq\Rabbit\Contracts;

interface RpcClientInterface
{
    public function call(string $queue, array $payload, string $replyQueue, int $timeout = 10): ?array;
}
