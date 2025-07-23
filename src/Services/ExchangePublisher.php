<?php

namespace LaravelMq\Rabbit\Services;

use Exception;
use JsonException;
use LaravelMq\Rabbit\Contracts\PublisherInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ExchangePublisher implements PublisherInterface
{
    protected AMQPStreamConnection $connection;
    protected AMQPChannel $channel;

    /**
     * @throws Exception
     */
    public function __construct(string $host, int $port, string $user, string $password)
    {
        $this->connection = new AMQPStreamConnection($host, $port, $user, $password);
        $this->channel = $this->connection->channel();
    }

    /**
     * @throws JsonException
     */
    public function publish(string $queueOrExchange, array $payload, ?string $routingKey = null): void
    {
        $message = new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        if ($routingKey !== null) {
            $this->channel->exchange_declare($queueOrExchange, 'topic', false, true, false);
            $this->channel->basic_publish($message, $queueOrExchange, $routingKey);
        } else {
            $this->channel->queue_declare($queueOrExchange, false, true, false, false);
            $this->channel->basic_publish($message, '', $queueOrExchange);
        }
    }

    /**
     * @throws Exception
     */
    public function __destruct()
    {
        $this->channel->close();
        $this->connection->close();
    }
}
