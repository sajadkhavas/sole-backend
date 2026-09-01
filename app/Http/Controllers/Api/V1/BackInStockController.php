<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackInStockIntent;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BackInStockController extends Controller
{
    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', 'in:p05-v1'],
        ]);

        $publishedProduct = Product::query()->published()->findOrFail($product->getKey());
        $variant = ProductVariant::query()
            ->active()
            ->where('product_id', $publishedProduct->getKey())
            ->with('inventoryBalances')
            ->findOrFail((int) $validated['variant_id']);

        $available = (int) $variant->inventoryBalances->sum(fn ($balance): int => max(0, (int) $balance->on_hand - (int) $balance->reserved));
        if ($available > 0) {
            return response()->json(['error' => 'variant_already_available'], 409);
        }

        $email = Str::lower(trim((string) $validated['email']));
        $hash = hash('sha256', $email);

        $intent = BackInStockIntent::query()->updateOrCreate(
            ['product_variant_id' => $variant->getKey(), 'email_hash' => $hash],
            [
                'contact_email' => $email,
                'consent_version' => 'p05-v1',
                'consent_granted_at' => now(),
                'source' => 'pdp',
                'status' => 'pending',
            ],
        );

        return response()->json([
            'status' => 'registered',
            'intent_id' => $intent->getKey(),
            'notification_delivery' => 'deferred_to_p09',
        ], $intent->wasRecentlyCreated ? 201 : 200);
    }
}
