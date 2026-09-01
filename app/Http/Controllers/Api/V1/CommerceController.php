<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CommerceController extends Controller
{
    public function cart(Request $request, CartService $carts): JsonResponse
    {
        $token = $request->header('X-Sole-Cart');
        if ($token !== null && ! Validator::make(['token' => $token], ['token' => ['uuid']])->passes()) {
            $token = null;
        }

        try {
            $cart = $carts->resolve($token, $request->user('sanctum'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json(['data' => $carts->payload($cart)])->header('X-Sole-Cart', $cart->public_id);
    }

    public function putItem(Request $request, int $variant, CartService $carts): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:99']]);

        try {
            $cart = $this->requiredCart($request);
            $carts->setQuantity($cart, ProductVariant::query()->findOrFail($variant), $data['quantity']);

            return response()->json(['data' => $carts->payload($cart->refresh())]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function deleteItem(Request $request, int $variant, CartService $carts): JsonResponse
    {
        $cart = $this->requiredCart($request);
        $cart->items()->where('product_variant_id', $variant)->delete();

        return response()->json(['data' => $carts->payload($cart->refresh())]);
    }

    public function checkout(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(['address_id' => ['required', 'integer']]);
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || ! Validator::make(['key' => $idempotencyKey], ['key' => ['required', 'uuid']])->passes()) {
            return response()->json(['message' => 'A UUID Idempotency-Key header is required.'], 422);
        }

        $user = $request->user();
        $address = CustomerAddress::query()->where('user_id', $user->id)->findOrFail($data['address_id']);

        try {
            $payload = $checkout->create($user, $this->requiredCart($request, allowConverted: true), $address, $idempotencyKey);

            return response()->json(['data' => $payload], 201);
        } catch (RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'policy') ? 503 : 409;

            return response()->json(['message' => $exception->getMessage()], $status);
        }
    }

    public function orders(Request $request, CheckoutService $checkout): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->latest('id')
            ->paginate(min(50, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order): array => $checkout->orderPayload($order))->all(),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()],
        ]);
    }

    public function order(Request $request, string $order, CheckoutService $checkout): JsonResponse
    {
        $model = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('public_id', $order)
            ->with('items')
            ->firstOrFail();

        return response()->json(['data' => $checkout->orderPayload($model)]);
    }

    private function requiredCart(Request $request, bool $allowConverted = false): Cart
    {
        $token = $request->header('X-Sole-Cart');

        if (! is_string($token) || ! Validator::make(['token' => $token], ['token' => ['required', 'uuid']])->passes()) {
            throw new RuntimeException('A valid cart token is required.');
        }

        $cart = Cart::query()->where('public_id', $token)
            ->when(! $allowConverted, fn ($query) => $query->where('status', 'active'))
            ->firstOrFail();
        $user = $request->user('sanctum');

        if ($cart->user_id !== null && $cart->user_id !== $user?->id) {
            throw new RuntimeException('This cart belongs to another customer.');
        }

        return $cart;
    }
}
