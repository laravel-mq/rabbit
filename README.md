# Laravel RabbitMQ Package

A simple and lightweight RabbitMQ integration package for Laravel applications. This package provides an easy way to publish messages to RabbitMQ exchanges and consume messages from queues with built-in JSON schema validation.

## What it does

This package gives you:
- **Message Publishing**: Send messages to RabbitMQ exchanges with routing keys
- **Queue Consumption**: Consume messages from queues with manual acknowledgment (prefetch 1, at-least-once delivery)
- **RPC Support**: Handle request-response patterns
- **Schema Validation**: Validate message payloads against JSON schemas
- **Console Commands**: Easy-to-use Artisan commands for consuming queues

## Installation

1. Install the package via Composer:
```bash
composer require laravelmq/rabbit
```

2. Publish the configuration file:
```bash
php artisan vendor:publish --tag=rabbitmq-config
```

3. Add your RabbitMQ connection details to your `.env` file:
```env
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

## Basic Usage

### Publishing Messages

```php
use LaravelMq\Rabbit\Contracts\PublisherInterface;

class OrderController extends Controller
{
    public function store(Request $request, PublisherInterface $publisher)
    {
        $publisher->publish(
            'orders.exchange',
            ['order_id' => 123, 'status' => 'created'],
            'order.created',
            base_path('schemas/order.schema.json')
        );
    }
}
```

### Creating Queue Handlers

Create a handler class that implements the `QueueHandler` interface:

```php
<?php

namespace App\Handlers;

use LaravelMq\Rabbit\Contracts\QueueHandler;
use PhpAmqpLib\Message\AMQPMessage;

class OrderCreatedHandler implements QueueHandler
{
    public function queue(): string
    {
        return 'orders.queue';
    }

    public function schemaPath(): ?string
    {
        return resource_path('schemas/order-created.json');
    }

    public function handle(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        
        Order::create($payload);
    }
    
    public function mode(): string
    {
        return 'rpc';
    }
}
```

### Consuming Queues

Use the Artisan command to start consuming messages:

```bash
php artisan rabbitmq:consume

php artisan rabbitmq:consume --queue=orders.queue,notifications.queue

php artisan rabbitmq:consume --mode=rpc
```

### Delivery guarantees

The consumer uses **manual acknowledgements with a prefetch of 1** for every mode (`basic`, `event`, `rpc`):

- The broker sends a worker one message at a time. The next one is delivered only after the current one is acked or nacked.
- A message is **acked after `handle()` returns** without throwing.
- If schema validation fails or `handle()` throws, the message is **nacked without requeue**. RabbitMQ dead-letters it if the queue has a dead-letter exchange; otherwise the message is dropped. Failed messages are never requeued, so a poison message cannot loop forever.
- If the connection drops while a handler is running, the ack can't be sent. The worker logs a warning and reconnects, and RabbitMQ **redelivers** the unacked message (`$message->isRedelivered()` is `true`).

Delivery is therefore **at-least-once**: a message can be handled more than once (for example, when the handler finished but the connection dropped before the ack). **Handlers must be idempotent.** Do not call `$message->ack()`, `nack()` or `reject()` from a handler; the consumer does this for you.

After a reconnect, every queue is re-subscribed exactly once, including event queues with their exchange and routing key.
