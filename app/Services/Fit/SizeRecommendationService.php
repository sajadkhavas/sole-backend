<?php

namespace App\Services\Fit;

use App\Models\SizeGuide;

class SizeRecommendationService
{
    public function recommend(SizeGuide $guide, int $footLengthMm): array
    {
        $entries = $guide->entries;
        $exact = $entries->first(fn ($entry) => $footLengthMm >= $entry->foot_length_min_mm && $footLengthMm <= $entry->foot_length_max_mm);

        if ($exact) {
            return $this->result($exact->eu_size, $guide->verified_at ? 'high' : 'medium', 'measurement_within_range');
        }

        $nearest = $entries->sortBy(fn ($entry) => min(abs($footLengthMm - $entry->foot_length_min_mm), abs($footLengthMm - $entry->foot_length_max_mm)))->first();
        if (! $nearest) {
            return $this->result(null, 'unavailable', 'no_verified_size_data');
        }

        $distance = min(abs($footLengthMm - $nearest->foot_length_min_mm), abs($footLengthMm - $nearest->foot_length_max_mm));
        if ($distance > 5) {
            return $this->result(null, 'low', 'measurement_outside_supported_range');
        }

        return $this->result($nearest->eu_size, 'low', 'nearest_range_boundary');
    }

    private function result(string|float|null $size, string $confidence, string $reason): array
    {
        return [
            'recommended_eu_size' => $size === null ? null : (string) $size,
            'confidence' => $confidence,
            'reason' => $reason,
            'disclaimer' => 'This is guidance, not a guarantee. Brand shape, width and personal preference can change fit.',
        ];
    }
}
