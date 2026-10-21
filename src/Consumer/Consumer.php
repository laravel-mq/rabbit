<?php

namespace LaravelMq\Rabbit\Consumer;

use Exception;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use Throwable;

class Consumer
{
    /**
     * Messages the broker may push to this worker before they are acked.
     * Handlers run one at a time, so anything above 1 only sits in the
     * client buffer and is exposed to loss/redelivery on connection drops.
     */
    public const PREFETCH_COUNT = 1;

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
        $this->channel->basic_qos(0, self::PREFETCH_COUNT, false);

        Log::info("[RabbitMQ] Connected successfully.");
    }

    /**
     * Full auto-reconnect logic (channel + connection + handlers).
     * Every registered consumer is re-subscribed exactly once.
     */
    private function reconnect(): void
    {
        Log::warning("[RabbitMQ] Lost connection — reconnecting in 2 seconds...");

        // Drop the old connection so it cannot keep holding unacked deliveries.
        $this->close();
        sleep(2);

        while (true) {
            try {
                $this->connect();

                foreach ($this->registeredConsumers as $consumer) {
                    [$queue, $callback, , $mode, $routingKey, $exchange] = $consumer;
                    $this->subscribe($queue, $callback, $mode, $routingKey, $exchange);
                }

                Log::info("[RabbitMQ] Reconnected + consumers restored.");

                return;

            } catch (Exception $e) {
                Log::error("[RabbitMQ] Reconnect failed: {$e->getMessage()}");
                $this->close();
                sleep(5);
            }
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
        if ($mode === 'event') {
            if (!$exchange) {
                throw new Exception("Missing exchange for event queue: {$queue}");
            }
            if (!$routingKey) {
                throw new Exception("Missing routing key for event queue: {$queue}");
            }
        }

        $this->registeredConsumers[] = [$queue, $callback, $isRpc, $mode, $routingKey, $exchange];

        $this->subscribe($queue, $callback, $mode, $routingKey, $exchange);
    }

    /**
     * Declare the queue (and exchange/binding for events) and start consuming
     * on the current channel. Acknowledgements are manual for every mode; the
     * callback is responsible for ack/nack.
     */
    private function subscribe(
        string $queue,
        callable $callback,
        string $mode,
        ?string $routingKey,
        ?string $exchange
    ): void {
        if ($mode === 'event') {
            $this->channel->exchange_declare($exchange, 'topic', true, false, false);
            $this->channel->queue_declare($queue, false, true, false, false);
            $this->channel->queue_bind($queue, $exchange, $routingKey);
        } else {
            $this->channel->queue_declare($queue, false, true, false, false);
        }

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
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
            // A failed ack/nack inside a handler callback can tear the
            // connection down without wait() ever seeing it; the dead channel
            // then fails with an \Error instead of an AMQP exception.
            if (!$this->channel->is_open() || !$this->connection->isConnected()) {
                throw new AMQPConnectionClosedException('Channel or connection is no longer open.');
            }

            $this->channel->wait(null, false, 30);

        } catch (AMQPTimeoutException $e) {
            return;

        } catch (AMQPChannelClosedException|AMQPConnectionClosedException $e) {
            Log::error("[RabbitMQ] Connection lost: {$e->getMessage()}");
            $this->reconnect();

        } catch (Throwable $e) {
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
