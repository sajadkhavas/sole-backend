<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\OtpChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    public function request(Request $request, OtpChallengeService $service): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $challenge = $service->request($request->user(), $data['phone'], $request);

        return response()->json([
            'data' => [
                'id' => $challenge->id,
                'expires_at' => $challenge->expires_at->toISOString(),
                'resend_available_at' => $challenge->resend_available_at->toISOString(),
            ],
        ], 202);
    }

    public function verify(Request $request, OtpChallengeService $service): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
        ]);

        $profile = $service->verify(
            $request->user(),
            $data['challenge_id'],
            (string) $data['code'],
        );

        return response()->json([
            'data' => [
                'phone_e164' => $profile->phone_e164,
                'phone_verified_at' => $profile->phone_verified_at?->toISOString(),
            ],
        ]);
    }
}
