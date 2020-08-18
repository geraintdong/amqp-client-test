<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpReceiver extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:receive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public const FIRST_TOPIC = 'kern.*';
    public const SECOND_TOPIC = '*.critical';
    public const ALL_TOPICS = [
        self::FIRST_TOPIC,
        self::SECOND_TOPIC,
    ];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $connection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
        $channel = $connection->channel();

        $channel->queue_declare('rpc_queue', false, false, false, false);

        function fib($n)
        {
            if ($n == 0) {
                return 0;
            }
            if ($n == 1) {
                return 1;
            }
            return fib($n-1) + fib($n-2);
        }

        // $channel->exchange_declare('topic_logs', 'topic', false, false, false);
        // list($queueName, ,) = $channel->queue_declare('', false, false, true, false);

        // $channel->queue_bind($queueName, 'topic_logs', self::FIRST_TOPIC);
        // foreach (self::ALL_TOPICS as $bindingKey) {
        //     $channel->queue_bind($queueName, 'topic_logs', $bindingKey);
        // }

        echo "\n🔴 Waiting for messages. To exit press CTRL+C.\n";

        $callback = function (AMQPMessage $request) {
            echo "\n▶️ Msg content: {$request->body}\n";
            $input = intval($request->body);
            $response = new AMQPMessage(
                (string) fib($input),
                ['correlation_id' => $request->get('correlation_id')]
            );
            sleep([1, 2, 3][array_rand([1, 2, 3])]);
            echo "\n✅ Done processing.\n";
            $request->delivery_info['channel']->basic_publish(
                $response,
                '',
                $request->get('reply_to')
            );
            $request->delivery_info['channel']->basic_ack(
                $request->delivery_info['delivery_tag']
            );
        };
        $channel->basic_qos(null, 1, null);
        $channel->basic_consume('rpc_queue', '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

        return 0;
    }
}
