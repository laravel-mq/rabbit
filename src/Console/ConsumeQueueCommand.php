<?php

namespace LaravelMq\Rabbit\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use LaravelMq\Rabbit\Consumer\Consumer;
use LaravelMq\Rabbit\Contracts\QueueHandler;
use LaravelMq\Rabbit\Services\SchemaValidator;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;
use Illuminate\Support\Facades\App;

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

        $handlers = App::make('app')->tagged(QueueHandler::class);

        if (empty($handlers)) {
            $this->components->error('No queue handlers found. Make sure your handlers are tagged and implement the QueueHandler interface.');
            return;
        }

        $selectedMode = $this->option('mode') ?? 'basic';

        $filtered = collect($handlers)->filter(fn($h) => $h->mode() === $selectedMode);

        if ($queueFilter = $this->option('queue')) {
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

        $isRpc = $selectedMode === 'rpc';

        foreach ($filtered as $handler) {
            $consumer->consume($handler->queue(), function (AMQPMessage $message) use ($handler, $isRpc) {
                $this->components->task("[{$handler->queue()}] Message received", function () use ($handler, $message, $isRpc) {
                    try {
                        $schemaPath = $handler->schemaPath();
                        if ($schemaPath !== null) {
                            $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
                            
                            $validator = new SchemaValidator();
                            $validator->validate($payload, $schemaPath);
                        }
                        
                        $handler->handle($message);

                        $props = $message->get_properties();

                        Log::info("[RabbitMQ] Handled message from queue '{$handler->queue()}'", [
                            'correlation_id' => $props['correlation_id'] ?? null,
                            'reply_to' => $props['reply_to'] ?? null,
                        ]);

                        if ($isRpc) {
                            $message->ack();
                        }

                        return true;
                    } catch (Throwable $e) {
                        Log::error("[RabbitMQ] Failed to handle message", [
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
