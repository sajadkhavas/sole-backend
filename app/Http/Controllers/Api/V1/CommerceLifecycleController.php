<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Shipment;
use App\Services\Commerce\PaymentService;
use App\Services\Commerce\RefundService;
use App\Services\Commerce\ReturnService;
use App\Services\Commerce\ShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CommerceLifecycleController extends Controller
{
    public function shippingQuotes(Request $request, ShippingService $shipping): JsonResponse
    {
        $data = $request->validate(['address_id' => ['required', 'integer']]);
        $user = $request->user();
        $address = CustomerAddress::query()->where('user_id', $user->id)->findOrFail($data['address_id']);
        $cart = $this->requiredCart($request, $user->id);

        try {
            return response()->json(['data' => $shipping->quotes($user, $cart, $address)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function initiatePayment(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey === null) {
            return response()->json(['message' => 'A UUID Idempotency-Key header is required.'], 422);
        }

        $model = $this->customerOrder($request, $order);
        try {
            return response()->json(['data' => $payments->initiate($request->user(), $model, $idempotencyKey)], 201);
        } catch (RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'not enabled') || str_contains($exception->getMessage(), 'configured') ? 503 : 409;

            return response()->json(['message' => $exception->getMessage()], $status);
        }
    }

    public function verifyPayment(Request $request, string $payment, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'authority' => ['required', 'string', 'max:120'],
            'status' => ['required', 'string', 'max:20'],
        ]);
        $attempt = PaymentAttempt::query()->where('public_id', $payment)->firstOrFail();

        try {
            return response()->json(['data' => $payments->verify($request->user(), $attempt, $data['authority'], $data['status'])]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function reconcilePayment(Request $request, string $payment, PaymentService $payments): JsonResponse
    {
        $attempt = PaymentAttempt::query()->where('public_id', $payment)->firstOrFail();
        try {
            return response()->json(['data' => $payments->reconcile($request->user(), $attempt)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function requestReturn(Request $request, string $order, ReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:80'],
            'reason_text' => ['nullable', 'string', 'max:1000'],
        ]);
        $model = $this->customerOrder($request, $order)->load('shipment');

        try {
            return response()->json(['data' => $returns->request($request->user(), $model, $data['reason'], $data['reason_text'] ?? null)], 201);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function requestRefund(Request $request, string $order, RefundService $refunds): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:80']]);
        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey === null) {
            return response()->json(['message' => 'A UUID Idempotency-Key header is required.'], 422);
        }

        try {
            return response()->json([
                'data' => $refunds->request($request->user(), $this->customerOrder($request, $order), $idempotencyKey, $data['reason']),
            ], 201);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function shippingWebhook(Request $request, ShippingService $shipping): JsonResponse
    {
        $secret = trim((string) config('commerce.shipping.webhook_secret'));
        if ($secret === '') {
            return response()->json(['message' => 'Shipping webhook is not configured.'], 503);
        }

        $signature = trim((string) $request->header('X-Sole-Signature'));
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid shipping webhook signature.'], 401);
        }

        $data = $request->validate([
            'shipment_id' => ['required', 'uuid'],
            'event_id' => ['required', 'string', 'max:120'],
            'status' => ['required', 'in:ready,shipped,delivered,exception,cancelled'],
            'reason' => ['required', 'string', 'max:160'],
            'tracking_number' => ['nullable', 'string', 'max:160'],
        ]);
        $shipment = Shipment::query()->where('public_id', $data['shipment_id'])->firstOrFail();
        if ($shipment->provider !== config('commerce.shipping.provider')) {
            return response()->json(['message' => 'Shipping provider mismatch.'], 409);
        }

        try {
            $updated = $shipping->transition(
                $shipment,
                $data['event_id'],
                $data['status'],
                $data['reason'],
                $data['tracking_number'] ?? null,
                ['source' => 'signed_provider_webhook'],
            );

            return response()->json(['data' => [
                'id' => $updated->public_id,
                'status' => $updated->status,
                'tracking_number' => $updated->tracking_number,
            ]]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    private function customerOrder(Request $request, string $publicId): Order
    {
        return Order::query()->where('user_id', $request->user()->id)->where('public_id', $publicId)->firstOrFail();
    }

    private function requiredCart(Request $request, int $userId): Cart
    {
        $token = $request->header('X-Sole-Cart');
        if (! is_string($token) || ! Validator::make(['token' => $token], ['token' => ['required', 'uuid']])->passes()) {
            throw new RuntimeException('A valid cart token is required.');
        }

        $cart = Cart::query()->where('public_id', $token)->where('status', 'active')->firstOrFail();
        if ($cart->user_id !== null && $cart->user_id !== $userId) {
            throw new RuntimeException('This cart belongs to another customer.');
        }

        return $cart;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->passes() ? $key : null;
    }
}
