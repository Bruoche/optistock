<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the requesting user once their optimized route is ready.
 *
 * The frontend subscribes to its private user channel and filters incoming
 * events by `job_uuid` to match the request it is waiting on.
 *
 * Queued (ShouldBroadcast, not ShouldBroadcastNow) on purpose: the Reverb push
 * runs in a separate broadcast job, decoupled from OptimizeRouteJob's critical
 * path. A Reverb outage can therefore never throw inside the optimization job
 * and flip an already-cached successful result to "failed".
 */
class RouteOptimized implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array{
     *     ordered_stops: array<int, array{lat: float, lng: float, order: int}>,
     *     total_distance_m: int,
     *     total_duration_s: int
     * }  $data
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $jobUuid,
        public readonly array $data,
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
        return 'RouteOptimized';
    }

    /**
     * Push on a dedicated queue so the user-facing notification is never stuck
     * behind multi-minute OptimizeRouteJob runs on the default queue. Run a
     * worker for it: `php artisan queue:work --queue=broadcasts`.
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array{job_uuid: string, data: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return ['job_uuid' => $this->jobUuid, 'data' => $this->data];
    }
}
