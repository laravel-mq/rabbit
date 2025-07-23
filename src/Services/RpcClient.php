<?php

namespace LaravelMq\Rabbit\Services;

use Exception;
use Illuminate\Support\Str;
use JsonException;
use LaravelMq\Rabbit\Contracts\RpcClientInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RpcClient implements RpcClientInterface
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
    public function call(string $queue, array $payload, string $replyQueue, int $timeout = 10): ?array
    {
        $this->channel->queue_declare($replyQueue, false, true, false, false);

        $correlationId = (string)Str::uuid();
        $response = null;

        $this->channel->basic_consume(
            $replyQueue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use (&$response, $correlationId) {
                if ($message->get('correlation_id') === $correlationId) {
                    $response = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                }
                logger()->info('Received reply message', [
                    'correlation_id' => $message->get('correlation_id'),
                    'match' => $message->get('correlation_id') === $correlationId,
                    'body' => $message->getBody(),
                ]);
            }
        );

        $message = new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
            'content_type' => 'application/json',
            'correlation_id' => $correlationId,
            'reply_to' => $replyQueue,
        ]);

        $this->channel->basic_publish($message, '', $queue);

        $start = microtime(true);
        while (!$response && (microtime(true) - $start) < $timeout) {
            $this->channel->wait(null, false, $timeout);
        }

        return $response;
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
