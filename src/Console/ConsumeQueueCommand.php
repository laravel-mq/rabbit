<?php

namespace LaravelMq\Rabbit\Console;

use Exception;
use Illuminate\Console\Command;
use LaravelMq\Rabbit\Consumer\Consumer;
use LaravelMq\Rabbit\Contracts\QueueHandler;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class ConsumeQueueCommand extends Command
{
    protected $signature = 'rabbitmq:consume
    {--queue= : Comma-separated list of queues to consume}
    {--mode=basic : Consumption mode cmd, rpc, event}';

    protected $description = 'Consume messages from a RabbitMQ queue';

    protected bool $shouldStop = false;

    /**
     * Handle queue consumption.
     *
     * @throws Exception
     */
    public function handle(Consumer $consumer): void
    {
        $this->setupSignalHandling();

        $handlers = app()->tagged(QueueHandler::class);

        if (empty($handlers)) {
            $this->components->error('No queue handlers found. Make sure your handlers are tagged and implement the QueueHandler interface.');
            return;
        }

        $queueFilter = $this->option('queue');
        $filtered = collect($handlers);

        if ($queueFilter) {
            $filterList = array_map('trim', explode(',', $queueFilter));
            $filtered = $filtered->filter(fn($h) => in_array($h->queue(), $filterList, true));
        }

        if ($filtered->isEmpty()) {
            $this->components->error('No handlers matched the provided --queue option.');
            return;
        }

        $queues = $filtered->map(fn($handler) => $handler->queue())->unique()->values()->all();

        $this->newLine();
        $this->components->info('RabbitMQ consumer ready');
        $this->components->twoColumnDetail('Queues', '<fg=cyan>' . implode('</>, <fg=cyan>', $queues) . '</>');
        $this->components->twoColumnDetail('Mode', $this->option('mode'));
        $this->components->twoColumnDetail('Started at', now()->toDateTimeString());
        $this->newLine();

        $isRpc = $this->option('mode') === 'rpc';

        foreach ($filtered as $handler) {
            $consumer->consume($handler->queue(), function (AMQPMessage $message) use ($handler, $isRpc) {
                $this->components->task("[{$handler->queue()}] Message received", function () use ($handler, $message, $isRpc) {
                    try {
                        $handler->handle($message);

                        $props = $message->get_properties();

                        logger()->info("[RabbitMQ] Handled message from queue '{$handler->queue()}'", [
                            'correlation_id' => $props['correlation_id'] ?? null,
                            'reply_to' => $props['reply_to'] ?? null,
                        ]);
                        
                        if ($isRpc || !array_key_exists('delivery_mode', $props)) {
                            $message->ack();
                        }

                        return true;
                    } catch (Throwable $e) {
                        logger()->error("[RabbitMQ] Failed to handle message", [
                            'queue' => $handler->queue(),
                            'error' => $e->getMessage(),
                        ]);

                        throw new Exception($e->getMessage(), $e->getCode(), $e);
                    }
                });
            }, $isRpc);
        }

        while (!$this->shouldStop) {
            $consumer->wait();
        }

        $this->components->info('RabbitMQ consumer stopped gracefully.');
    }


    protected function setupSignalHandling(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->shouldStop = true;
        });

        pcntl_signal(SIGINT, function () {
            $this->shouldStop = true;
        });
    }
}
