<?php

namespace App\Events;

use App\Models\AccessLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccessLogCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $accessLog;

    /**
     * Create a new event instance.
     */
    public function __construct(AccessLog $accessLog)
    {
        $this->accessLog = $accessLog;
    }
}
