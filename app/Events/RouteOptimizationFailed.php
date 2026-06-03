<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the requesting user when optimization fails for any reason
 * (upstream API error/timeout, invalid response, or a crashed job).
 *
 * Always emitted on failure so the frontend stops waiting instead of hanging
 * on a spinner indefinitely.
 */
class RouteOptimizationFailed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array{code: string, message: string}  $error
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $jobUuid,
        public readonly array $error,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'RouteOptimizationFailed';
    }

    /**
     * @return array{job_uuid: string, error: array{code: string, message: string}}
     */
    public function broadcastWith(): array
    {
        return ['job_uuid' => $this->jobUuid, 'error' => $this->error];
    }
}
