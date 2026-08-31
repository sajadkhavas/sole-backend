<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    private const USER_AUDIT_FIELDS = [
        'id',
        'is_active',
        'email_verified_at',
        'last_login_at',
        'account_status',
        'deletion_requested_at',
        'created_at',
        'updated_at',
    ];

    public function __construct(private readonly AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $this->logger->record('created', $model, null, $this->safeAttributes($model, $model->attributesToArray()));
    }

    public function updated(Model $model): void
    {
        $changes = array_keys($model->getChanges());
        $before = [];

        foreach ($changes as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $after = $model->only($changes);

        $this->logger->record(
            'updated',
            $model,
            $this->safeAttributes($model, $before),
            $this->safeAttributes($model, $after),
        );
    }

    public function deleted(Model $model): void
    {
        $this->logger->record('deleted', $model, $this->safeAttributes($model, $model->attributesToArray()), null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function safeAttributes(Model $model, array $attributes): array
    {
        if (! $model instanceof User) {
            return $attributes;
        }

        return array_intersect_key($attributes, array_flip(self::USER_AUDIT_FIELDS));
    }
}
