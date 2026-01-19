<?php

namespace LaravelMq\Rabbit\Contracts;

interface EventQueueHandler extends QueueHandler
{
    /**
     * Routing key for the event
     */
    public function routingKey(): string;

    /**
     * Exchange name for the event
     * Default can be overridden per event
     */
    public function exchange(): string;
}