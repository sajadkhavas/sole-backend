<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function record(string $action, Model $subject, ?array $before, ?array $after): AuditLog
    {
        /** @var Request|null $request */
        $request = app()->bound('request') ? request() : null;
        $requestId = $request?->headers->get('X-Request-ID');

        if ($requestId !== null && ! Str::isUuid($requestId)) {
            $requestId = null;
        }

        return AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'request_id' => $requestId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
