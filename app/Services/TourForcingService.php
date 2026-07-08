<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hard-writes a tour without the optimization API (feature 024). When optimization is
 * unavailable, the dispatcher supplies the tour's total drive duration by hand and the
 * stops are saved in their current (entered) order — no reorder, no upstream call. The
 * write reuses {@see TourRecorder}, so a create, an in-place edit, and a persistence
 * failure behave exactly as on the optimize path (a failed save surfaces as
 * `persist_failed`, never a silent unsaved route — D10/FR-014).
 */
class TourForcingService
{
    public function __construct(
        private readonly TourRecorder $recorder,
    ) {}

    /**
     * @param  array<int, array{lat: int|float|string, lng: int|float|string, duration_s: int}>  $stops
     * @param  string  $mode  The delivery mode saved with the tour (unused by routing here).
     * @param  bool  $loop  Tour shape: closed loop or open one-way (004).
     * @param  int  $travelDurationS  The manually entered total drive duration, in seconds.
     */
    public function force(int $userId, array $stops, string $mode, bool $loop, int $travelDurationS, ?int $editTourId = null): TourOptimizationResult
    {
        $orderedStops = $this->orderedStopsInInputOrder($stops);
        $durationsByCoordinate = $this->mapDurationsByCoordinate($stops);

        try {
            $tour = $this->recorder->record(
                $userId,
                $mode,
                $loop,
                $orderedStops,
                $durationsByCoordinate,
                null,
                $travelDurationS,
                $editTourId,
            );
        } catch (Throwable $e) {
            Log::error('Tour persistence failed (forced)', [
                'user_id' => $userId,
                'mode' => $mode,
                'loop' => $loop,
                'edit_tour_id' => $editTourId,
                'error' => $e->getMessage(),
            ]);

            return TourOptimizationResult::failed(TourOptimizationService::persistError());
        }

        return TourOptimizationResult::ready([
            'ordered_stops' => $orderedStops,
            'total_distance_m' => null,
            // Driving-only total (matches the optimize payload); per-stop time is added
            // by the frontend, never folded into this figure.
            'total_duration_s' => $travelDurationS,
            'id' => $tour->id,
        ]);
    }

    /**
     * The stops kept in the dispatcher's input order (position = index). Each coordinate is
     * rounded to the same precision an optimized stop is stored at — but NOT sorted (unlike
     * the optimize path's normalizer): a forced tour preserves the entered order verbatim.
     *
     * @param  array<int, array{lat: int|float|string, lng: int|float|string, duration_s: int}>  $stops
     * @return array<int, array{lat: float, lng: float, order: int}>
     */
    private function orderedStopsInInputOrder(array $stops): array
    {
        $orderedStops = [];
        foreach (array_values($stops) as $order => $stop) {
            $orderedStops[] = [
                'lat' => round((float) $stop['lat'], CoordinateNormalizer::PRECISION),
                'lng' => round((float) $stop['lng'], CoordinateNormalizer::PRECISION),
                'order' => $order,
            ];
        }

        return $orderedStops;
    }

    /**
     * Map each coordinate to its queue of delivery durations, keyed by the same rounding
     * {@see TourRecorder} re-reads them with (a queue keeps duplicate coordinates unambiguous).
     *
     * @param  array<int, array{lat: int|float|string, lng: int|float|string, duration_s: int}>  $stops
     * @return array<string, list<int>>
     */
    private function mapDurationsByCoordinate(array $stops): array
    {
        $durationsByCoordinate = [];
        foreach ($stops as $stop) {
            $key = TourRecorder::coordinateKey((float) $stop['lat'], (float) $stop['lng']);
            $durationsByCoordinate[$key][] = (int) $stop['duration_s'];
        }

        return $durationsByCoordinate;
    }
}
