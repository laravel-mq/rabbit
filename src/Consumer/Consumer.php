<?php

namespace LaravelMq\Rabbit\Consumer;

use Exception;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;

class Consumer
{
    protected AMQPStreamConnection $connection;
    protected AMQPChannel $channel;

    protected array $registeredConsumers = [];

    protected string $host;
    protected int $port;
    protected string $user;
    protected string $password;

    /**
     * Optimized constructor with heartbeat + timeouts.
     */
    public function __construct(string $host, int $port, string $user, string $password)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;

        $this->connect();
    }

    /**
     * Create RMQ connection with good defaults for long-running workers.
     * @throws Exception
     */
    private function connect(): void
    {
        Log::info("[RabbitMQ] Connecting...");

        $this->connection = new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            '/',
            false,
            'AMQPLAIN',
            null,
            'en_US',
            30,
            30,
            null,
            true,
            0
        );

        $this->channel = $this->connection->channel();

        Log::info("[RabbitMQ] Connected successfully.");
    }

    /**
     * Full auto-reconnect logic (channel + connection + handlers).
     */
    private function reconnect(): void
    {
        Log::warning("[RabbitMQ] Lost connection — reconnecting in 2 seconds...");
        sleep(2);

        try {
            $consumersToRestore = $this->registeredConsumers;
            $this->registeredConsumers = [];
            
            $this->connect();

            foreach ($consumersToRestore as $consumer) {
                [$queue, $callback, $isRpc, $mode, $routingKey, $exchange] = $consumer;
                $this->consume($queue, $callback, $isRpc, $mode, $routingKey, $exchange);
            }

            Log::info("[RabbitMQ] Reconnected + consumers restored.");

        } catch (Exception $e) {
            Log::error("[RabbitMQ] Reconnect failed: {$e->getMessage()}");
            sleep(5);
            $this->reconnect();
        }
    }

    /**
     * Register consumer and remember it for reconnection restore.
     * @throws Exception
     */
    public function consume(
        string $queue,
        callable $callback,
        bool $isRpc = false,
        string $mode = 'basic',
        ?string $routingKey = null,
        ?string $exchange = null
    ): void {
        $this->registeredConsumers[] = [$queue, $callback, $isRpc, $mode, $routingKey, $exchange];

        if ($mode === 'event') {
            if (!$exchange) {
                throw new Exception("Missing exchange for event queue: {$queue}");
            }
            $this->channel->exchange_declare($exchange, 'topic', true, false, false);
            $this->channel->queue_declare($queue, false, true, false, false);

            if (!$routingKey) {
                throw new Exception("Missing routing key for event queue: {$queue}");
            }

            $this->channel->queue_bind($queue, $exchange, $routingKey);

            $this->channel->basic_consume(
                $queue,
                '',
                false,
                true,
                false,
                false,
                $callback
            );

            return;
        }

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
     * Safe wait() loop with heartbeat + timeout + reconnect.
     */
    public function wait(): void
    {
        try {
            $this->channel->wait(null, false, 30);

        } catch (AMQPTimeoutException $e) {
            return;

        } catch (AMQPChannelClosedException|AMQPConnectionClosedException $e) {
            Log::error("[RabbitMQ] Connection lost: {$e->getMessage()}");
            $this->reconnect();

        } catch (Exception $e) {
            Log::error("[RabbitMQ] Unexpected wait() failure: {$e->getMessage()}");
            $this->reconnect();
        }
    }

    public function close(): void
    {
        try {
            if ($this->channel?->is_open()) {
                $this->channel->close();
            }
            if ($this->connection?->isConnected()) {
                $this->connection->close();
            }
        } catch (Exception $e) {
            Log::error("[RabbitMQ] Failed to close connection: {$e->getMessage()}");
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
