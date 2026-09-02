<?php

namespace App\Services\Engagement;

use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LoyaltyLedgerService
{
    public function balance(User $user): int
    {
        return (int) LoyaltyLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->sum('points_delta');
    }

    public function earn(
        User $user,
        int $points,
        string $idempotencyKey,
        string $reason,
        ?Order $order = null,
        ?CarbonInterface $expiresAt = null,
    ): LoyaltyLedgerEntry {
        $this->assertPositivePoints($points);

        return DB::transaction(function () use ($user, $points, $idempotencyKey, $reason, $order, $expiresAt): LoyaltyLedgerEntry {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            return $this->appendIdempotently(
                $lockedUser,
                'earn',
                $points,
                $idempotencyKey,
                $reason,
                $order,
                now(),
                $expiresAt,
            );
        }, 3);
    }

    public function redeem(
        User $user,
        int $points,
        string $idempotencyKey,
        string $reason,
        ?Order $order = null,
    ): LoyaltyLedgerEntry {
        $this->assertPositivePoints($points);

        return DB::transaction(function () use ($user, $points, $idempotencyKey, $reason, $order): LoyaltyLedgerEntry {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = $this->existing($lockedUser, $idempotencyKey);
            if ($existing !== null) {
                $this->assertIdempotentMatch($existing, 'redeem', -$points);

                return $existing;
            }

            if ($this->balance($lockedUser) < $points) {
                throw new RuntimeException('Insufficient loyalty balance.');
            }

            return $this->appendIdempotently(
                $lockedUser,
                'redeem',
                -$points,
                $idempotencyKey,
                $reason,
                $order,
                now(),
            );
        }, 3);
    }

    public function release(
        User $user,
        int $points,
        string $idempotencyKey,
        string $reason,
        ?Order $order = null,
    ): LoyaltyLedgerEntry {
        $this->assertPositivePoints($points);

        return DB::transaction(function () use ($user, $points, $idempotencyKey, $reason, $order): LoyaltyLedgerEntry {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            return $this->appendIdempotently(
                $lockedUser,
                'release',
                $points,
                $idempotencyKey,
                $reason,
                $order,
                now(),
            );
        }, 3);
    }

    public function expireDue(): int
    {
        $expired = 0;

        LoyaltyLedgerEntry::query()
            ->where('type', 'earn')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (LoyaltyLedgerEntry $entry) use (&$expired): void {
                if ($this->expireEarn($entry) !== null) {
                    $expired++;
                }
            });

        return $expired;
    }

    public function expireEarn(LoyaltyLedgerEntry $earn): ?LoyaltyLedgerEntry
    {
        if ($earn->type !== 'earn' || $earn->points_delta <= 0 || $earn->expires_at === null || $earn->expires_at->isFuture()) {
            throw new RuntimeException('Only due positive earn entries may expire.');
        }

        return DB::transaction(function () use ($earn): LoyaltyLedgerEntry {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($earn->user_id);
            $key = 'expire:'.$earn->public_id;
            $existing = $this->existing($lockedUser, $key);
            if ($existing !== null) {
                return $existing;
            }

            $points = min(max(0, $this->balance($lockedUser)), (int) $earn->points_delta);

            return $this->appendIdempotently(
                $lockedUser,
                'expire',
                -$points,
                $key,
                'scheduled_expiry',
                $earn->order_id ? Order::query()->find($earn->order_id) : null,
                now(),
            );
        }, 3);
    }

    private function appendIdempotently(
        User $user,
        string $type,
        int $pointsDelta,
        string $idempotencyKey,
        string $reason,
        ?Order $order,
        ?CarbonInterface $availableAt,
        ?CarbonInterface $expiresAt = null,
    ): LoyaltyLedgerEntry {
        $key = trim($idempotencyKey);
        $safeReason = trim($reason);
        if ($key === '' || mb_strlen($key) > 160) {
            throw new RuntimeException('Invalid loyalty idempotency key.');
        }
        if ($safeReason === '' || mb_strlen($safeReason) > 120) {
            throw new RuntimeException('Invalid loyalty reason.');
        }

        $existing = $this->existing($user, $key);
        if ($existing !== null) {
            $this->assertIdempotentMatch($existing, $type, $pointsDelta);

            return $existing;
        }

        return LoyaltyLedgerEntry::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'order_id' => $order?->id,
            'type' => $type,
            'points_delta' => $pointsDelta,
            'idempotency_key' => $key,
            'reason' => $safeReason,
            'available_at' => $availableAt,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
    }

    private function existing(User $user, string $key): ?LoyaltyLedgerEntry
    {
        return LoyaltyLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', trim($key))
            ->first();
    }

    private function assertIdempotentMatch(LoyaltyLedgerEntry $entry, string $type, int $pointsDelta): void
    {
        if ($entry->type !== $type || (int) $entry->points_delta !== $pointsDelta) {
            throw new RuntimeException('Loyalty idempotency key was reused with different semantics.');
        }
    }

    private function assertPositivePoints(int $points): void
    {
        if ($points <= 0 || $points > 1_000_000_000) {
            throw new RuntimeException('Loyalty points must be a positive bounded integer.');
        }
    }
}
