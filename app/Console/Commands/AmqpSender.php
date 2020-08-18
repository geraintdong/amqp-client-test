<?php

namespace App\Console\Commands;

use Exception;
use Faker\Factory;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpSender extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:send {input=30...}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return int
     * @throws Exception
     */
    public function handle()
    {
        $correlationId = uniqid();
        $response = null;
        $param = $this->argument('input');

        $connection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
        $channel = $connection->channel();

        // $channel->exchange_declare('topic_logs', 'topic', false, false, false);
        list($callbackQueue, ,) = $channel->queue_declare('rpc_queue', false, false, false, false);

        $channel->basic_consume(
            $callbackQueue,
            '',
            false,
            true,
            false,
            false,
            function (AMQPMessage $resp) use ($correlationId, &$response) {
                if ($resp->get('correlation_id') === $correlationId) {
                    $response = $resp->body;
                }
            });

        $msg = new AMQPMessage((string)$param, [ 'correlation_id' => $correlationId, 'reply_to' => $callbackQueue ]);
        $channel->basic_publish($msg, '', 'rpc_queue');
        while (!$response) {
            $channel->wait();
        }

        echo "\n✅ Result: $response\n";

        // for ($i = 0; $i < 10; $i++) {
        //     $faker = Factory::create();
        //     $msgBody = "$i {$faker->name}";
        //     $msg = new AMQPMessage(
        //         $msgBody // ,
        //         // [ 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT ]
        //     );
        //     $routingKey = str_replace(
        //         '*',
        //         $faker->domainWord,
        //         // AmqpReceiver::ALL_TOPICS[array_rand(AmqpReceiver::ALL_TOPICS)]
        //         AmqpReceiver::FIRST_TOPIC
        //     );
        //     echo "\nRouting key: `$routingKey`. Body message: `$msgBody`\n";
        //     $channel->basic_publish(
        //         $msg,
        //         'topic_logs',
        //         $routingKey
        //     );
        // }

        $channel->close();
        $connection->close();

        echo "\n✅ Messages have been sent successfully.\n";
        return 0;
    }
}
