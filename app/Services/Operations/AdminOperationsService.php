<?php

namespace App\Services\Operations;

use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ProductReview;
use App\Models\RefundRequest;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Commerce\OrderStateService;
use App\Services\Commerce\PaymentService;
use App\Services\Commerce\RefundService;
use App\Services\Commerce\ReturnService;
use App\Services\Commerce\ShippingService;
use App\Services\Engagement\LoyaltyLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

class AdminOperationsService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrderStateService $orders,
        private readonly PaymentService $payments,
        private readonly ShippingService $shipping,
        private readonly ReturnService $returns,
        private readonly RefundService $refunds,
        private readonly LoyaltyLedgerService $loyalty,
    ) {}

    public function cancelOrder(Order $order, string $reason): Order
    {
        Gate::authorize('update', $order);
        $before = $order->only(['status']);
        $updated = $this->orders->transition($order, 'cancelled', $this->reason($reason), ['source' => 'admin']);
        $this->audit->record('operations.order.cancelled', $updated, $before, $updated->only(['status']));

        return $updated;
    }

    public function reconcilePayment(PaymentAttempt $attempt): PaymentAttempt
    {
        Gate::authorize('reconcile', $attempt);
        $before = $attempt->only(['status', 'provider_code']);
        $this->payments->reconcileForOperations($attempt);
        $updated = $attempt->refresh();
        $this->audit->record('operations.payment.reconciled', $updated, $before, $updated->only(['status', 'provider_code']));

        return $updated;
    }

    public function transitionShipment(Shipment $shipment, string $toStatus, string $reason, ?string $trackingNumber = null): Shipment
    {
        Gate::authorize('update', $shipment);
        $before = $shipment->only(['status', 'tracking_number']);
        $eventKey = 'admin:'.Str::uuid();
        $updated = $this->shipping->transition($shipment, $eventKey, $toStatus, $this->reason($reason), $trackingNumber, ['source' => 'admin']);
        $this->audit->record('operations.shipment.transitioned', $updated, $before, $updated->only(['status', 'tracking_number']));

        return $updated;
    }

    public function transitionReturn(ReturnRequest $request, string $toStatus, string $reason): ReturnRequest
    {
        Gate::authorize('update', $request);
        $before = $request->only(['status']);
        $updated = $this->returns->transition($request, $toStatus);
        $this->audit->record('operations.return.transitioned', $updated, $before, ['status' => $updated->status, 'reason' => $this->reason($reason)]);

        return $updated;
    }

    public function transitionRefund(RefundRequest $refund, string $toStatus, string $reason, ?string $providerReference = null): RefundRequest
    {
        Gate::authorize('update', $refund);
        $before = $refund->only(['status', 'provider_reference']);
        $updated = $this->refunds->transition($refund, $toStatus, $providerReference);
        $this->audit->record('operations.refund.transitioned', $updated, $before, [
            'status' => $updated->status,
            'provider_reference' => $updated->provider_reference,
            'reason' => $this->reason($reason),
        ]);

        return $updated;
    }

    public function updateSupportCase(SupportCase $case, string $toStatus, string $priority, string $message): SupportCase
    {
        Gate::authorize('update', $case);
        $safeMessage = trim($message);
        if ($safeMessage === '' || mb_strlen($safeMessage) > 5000) {
            throw new RuntimeException('A bounded support reply is required.');
        }
        if (! in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new RuntimeException('Unsupported support priority.');
        }

        return DB::transaction(function () use ($case, $toStatus, $priority, $safeMessage): SupportCase {
            $locked = SupportCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $allowed = [
                'open' => ['open', 'waiting_customer', 'resolved'],
                'waiting_customer' => ['open', 'waiting_customer', 'resolved'],
                'resolved' => ['resolved', 'closed', 'open'],
                'closed' => [],
            ];
            if (! in_array($toStatus, $allowed[$locked->status] ?? [], true)) {
                throw new RuntimeException("Invalid support transition from {$locked->status} to {$toStatus}.");
            }
            $before = $locked->only(['status', 'priority', 'resolved_at']);
            SupportCase::withinStateTransition(function () use ($locked, $toStatus, $priority): void {
                $locked->forceFill([
                    'status' => $toStatus,
                    'priority' => $priority,
                    'resolved_at' => $toStatus === 'resolved' ? ($locked->resolved_at ?? now()) : null,
                ])->save();
            });
            $locked->events()->create([
                'actor_id' => auth()->id(),
                'type' => 'admin_reply',
                'body' => $safeMessage,
                'metadata' => ['status' => $toStatus, 'priority' => $priority],
                'created_at' => now(),
            ]);
            $this->audit->record('operations.support.updated', $locked, $before, $locked->only(['status', 'priority', 'resolved_at']));

            return $locked->refresh();
        }, 3);
    }

    public function moderateReview(ProductReview $review, string $decision, string $reason): ProductReview
    {
        Gate::authorize('update', $review);
        if (! in_array($decision, ['published', 'rejected'], true)) {
            throw new RuntimeException('Unsupported review moderation decision.');
        }

        return DB::transaction(function () use ($review, $decision, $reason): ProductReview {
            $locked = ProductReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new RuntimeException('Only pending reviews may be moderated.');
            }
            $before = $locked->only(['status', 'moderated_by', 'moderated_at', 'published_at']);
            ProductReview::withinStateTransition(function () use ($locked, $decision): void {
                $locked->forceFill([
                    'status' => $decision,
                    'moderated_by' => auth()->id(),
                    'moderated_at' => now(),
                    'published_at' => $decision === 'published' ? now() : null,
                ])->save();
            });
            $this->audit->record('operations.review.moderated', $locked, $before, [
                ...$locked->only(['status', 'moderated_by', 'moderated_at', 'published_at']),
                'reason' => $this->reason($reason),
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function adjustLoyalty(User $customer, string $direction, int $points, string $idempotencyKey, string $reason): LoyaltyLedgerEntry
    {
        Gate::authorize('create', LoyaltyLedgerEntry::class);
        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new RuntimeException('Unsupported loyalty adjustment direction.');
        }
        $key = 'admin:'.auth()->id().':'.trim($idempotencyKey);
        $safeReason = 'admin_adjustment:'.$this->reason($reason);
        $entry = $direction === 'credit'
            ? $this->loyalty->earn($customer, $points, $key, $safeReason)
            : $this->loyalty->redeem($customer, $points, $key, $safeReason);
        $this->audit->record('operations.loyalty.adjusted', $entry, null, $entry->only(['user_id', 'type', 'points_delta', 'reason']));

        return $entry;
    }

    private function reason(string $reason): string
    {
        $safe = trim($reason);
        if ($safe === '' || mb_strlen($safe) > 120) {
            throw new RuntimeException('A bounded operations reason is required.');
        }

        return $safe;
    }
}
