<?php

namespace LaravelMq\Rabbit;

use Illuminate\Support\ServiceProvider;
use LaravelMq\Rabbit\Console\ConsumeQueueCommand;
use LaravelMq\Rabbit\Consumer\Consumer;
use LaravelMq\Rabbit\Contracts\PublisherInterface;
use LaravelMq\Rabbit\Contracts\RpcClientInterface;
use LaravelMq\Rabbit\Services\ExchangePublisher;
use LaravelMq\Rabbit\Services\RpcClient;

class RabbitMQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/rabbitmq.php', 'rabbitmq');

        $this->app->singleton(PublisherInterface::class, function () {
            $config = config('rabbitmq');

            return new ExchangePublisher(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password']
            );
        });

        $this->app->singleton(RpcClientInterface::class, function () {
            $config = config('rabbitmq');

            return new RpcClient(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password']
            );
        });

        $this->app->singleton(Consumer::class, function () {
            $config = config('rabbitmq');

            return new Consumer(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password']
            );
        });
        
        $this->app->bind(ExchangePublisher::class, fn($app) => $app->make(PublisherInterface::class));
        $this->app->bind(RpcClient::class, fn($app) => $app->make(RpcClientInterface::class));
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
