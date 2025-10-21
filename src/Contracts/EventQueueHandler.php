<?php

namespace LaravelMq\Rabbit\Contracts;

interface EventQueueHandler extends QueueHandler
{
    public function routingKey(): string;
}