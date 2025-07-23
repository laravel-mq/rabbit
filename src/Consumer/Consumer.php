<?php

namespace LaravelMq\Rabbit\Consumer;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class Consumer
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
     * Register a single handler’s callback to a queue.
     */
    public function consume(string $queue, callable $callback, bool $isRpc = false): void
    {
        $this->channel->queue_declare($queue, false, true, false, false);

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            !$isRpc,
            false,
            false,
            $callback
        );
    }


    /**
     * Wait one cycle – used in external consumption loops.
     */
    public function wait(): void
    {
        $this->channel->wait();
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
