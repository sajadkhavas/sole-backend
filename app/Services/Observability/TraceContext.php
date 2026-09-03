<?php

namespace App\Services\Observability;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TraceContext
{
    /** @return array{request_id:string,trace_id:string,parent_span_id:?string,span_id:string,trace_flags:string,traceparent:string} */
    public function forRequest(Request $request): array
    {
        $traceId = null;
        $parentSpanId = null;
        $flags = '00';
        $incoming = strtolower(trim((string) $request->header('traceparent')));

        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', $incoming, $matches) === 1) {
            if ($matches[1] !== str_repeat('0', 32) && $matches[2] !== str_repeat('0', 16)) {
                $traceId = $matches[1];
                $parentSpanId = $matches[2];
                $flags = $matches[3];
            }
        }

        $traceId ??= bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        return [
            'request_id' => (string) Str::uuid(),
            'trace_id' => $traceId,
            'parent_span_id' => $parentSpanId,
            'span_id' => $spanId,
            'trace_flags' => $flags,
            'traceparent' => "00-{$traceId}-{$spanId}-{$flags}",
        ];
    }
}
