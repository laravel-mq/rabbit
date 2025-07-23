<?php

namespace LaravelMq\Rabbit;

use Illuminate\Support\ServiceProvider;
use LaravelMq\Rabbit\Console\ConsumeQueueCommand;
use LaravelMq\Rabbit\Consumer\Consumer;
use LaravelMq\Rabbit\Contracts\PublisherInterface;
use LaravelMq\Rabbit\Contracts\RpcClientInterface;
use LaravelMq\Rabbit\Services\ExchangePublisher;

class RabbitMQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/rabbitmq.php', 'rabbitmq');

        $this->app->singleton(PublisherInterface::class, function () {
            return new ExchangePublisher(
                config('rabbitmq.host'),
                config('rabbitmq.port'),
                config('rabbitmq.user'),
                config('rabbitmq.password')
            );
        });

        $this->app->singleton(RpcClientInterface::class, function () {
            return new RpcClient(
                config('rabbitmq.host'),
                config('rabbitmq.port'),
                config('rabbitmq.user'),
                config('rabbitmq.password')
            );
        });

        $this->app->singleton(Consumer::class, function () {
            return new Consumer(
                config('rabbitmq.host'),
                config('rabbitmq.port'),
                config('rabbitmq.user'),
                config('rabbitmq.password')
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConsumeQueueCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/Config/rabbitmq.php' => config_path('rabbitmq.php'),
            ], 'rabbitmq-config');
        }
    }
}
