<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');

            return response()->json(['status' => 'ready']);
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }
    }
}
