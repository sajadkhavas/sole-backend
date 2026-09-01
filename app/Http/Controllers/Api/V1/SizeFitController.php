<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FitEvent;
use App\Models\FitFeedback;
use App\Models\Product;
use App\Services\Fit\SizeRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SizeFitController extends Controller
{
    public function recommend(Request $request, Product $product, SizeRecommendationService $service): JsonResponse
    {
        abort_unless($product->status === 'published' && $product->published_at?->isPast(), 404);

        $data = $request->validate(['foot_length_mm' => ['required', 'integer', 'between:180,340'], 'request_id' => ['nullable', 'uuid']]);
        $guide = $product->sizeGuide()->where('status', 'published')->with('entries')->firstOrFail();
        $result = $service->recommend($guide, $data['foot_length_mm']);

        $event = ['product_id' => $product->id, 'event_name' => 'recommendation_requested', 'confidence_bucket' => $result['confidence'], 'recommended_size' => $result['recommended_eu_size'], 'created_at' => now()];
        isset($data['request_id'])
            ? FitEvent::query()->firstOrCreate(['request_id' => $data['request_id']], $event)
            : FitEvent::query()->create($event);

        return response()->json(['data' => $result]);
    }

    public function event(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'event_name' => ['required', Rule::in(['size_guide_opened', 'measurement_guidance_viewed'])],
            'request_id' => ['nullable', 'uuid'],
        ]);

        $event = ['product_id' => $product->id, 'event_name' => $data['event_name'], 'created_at' => now()];
        isset($data['request_id'])
            ? FitEvent::query()->firstOrCreate(['request_id' => $data['request_id']], $event)
            : FitEvent::query()->create($event);

        return response()->json(status: 204);
    }

    public function feedback(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
            'purchased_size' => ['required', 'string', 'max:16'],
            'overall_fit' => ['required', Rule::in(['tight', 'true_to_size', 'loose'])],
            'width_fit' => ['nullable', Rule::in(['narrow', 'standard', 'wide'])],
        ]);

        $feedback = FitFeedback::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id, 'product_variant_id' => $data['product_variant_id'] ?? null],
            [...$data, 'source' => 'customer'],
        );

        return response()->json(['data' => $feedback], 201);
    }
}
