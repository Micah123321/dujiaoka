<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $statusTransition;
    public $eventKey;
    public $snapshot;
    public $orderId;
    public $connectionName;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
        if (config('dujiaoka.manual_status_mail_outbox', false)) {
            $capture = $order->captureStatusMailTransition();
            if ($capture !== null) {
                $stored = $capture['order'];
                $this->statusTransition = $capture['transition'];
                $this->eventKey = $capture['key'];
                $this->snapshot = app(\App\Service\PaymentMailService::class)->statusRecipe($stored);
                $this->orderId = (int) $stored->getKey();
                $this->connectionName = $stored->getConnection()->getName();
            }
        }
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
