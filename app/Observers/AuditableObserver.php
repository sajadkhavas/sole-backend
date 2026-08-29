<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function __construct(private readonly AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $this->logger->record('created', $model, null, $model->attributesToArray());
    }

    public function updated(Model $model): void
    {
        $changes = array_keys($model->getChanges());
        $before = [];

        foreach ($changes as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->logger->record('updated', $model, $before, $model->only($changes));
    }

    public function deleted(Model $model): void
    {
        $this->logger->record('deleted', $model, $model->attributesToArray(), null);
    }
}
