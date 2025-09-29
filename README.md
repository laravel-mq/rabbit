# Laravel RabbitMQ Package

A simple and lightweight RabbitMQ integration package for Laravel applications. This package provides an easy way to publish messages to RabbitMQ exchanges and consume messages from queues with built-in JSON schema validation.

## What it does

This package gives you:
- **Message Publishing**: Send messages to RabbitMQ exchanges with routing keys
- **Queue Consumption**: Consume messages from queues with automatic acknowledgment
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
