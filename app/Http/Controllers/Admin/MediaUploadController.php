<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\Media\MediaProcessor;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaUploadController extends Controller
{
    public function store(Request $request, MediaUploadService $uploads): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'in:image/jpeg,image/png,image/webp'],
            'bytes' => ['required', 'integer', 'min:1', 'max:'.config('sole_media.max_bytes')],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'focal_x' => ['nullable', 'numeric', 'between:0,1'],
            'focal_y' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        return response()->json(['data' => $uploads->createIntent(
            $request->user(),
            $data['filename'],
            $data['mime'],
            (int) $data['bytes'],
            $data['alt_text'] ?? null,
            (float) ($data['focal_x'] ?? 0.5),
            (float) ($data['focal_y'] ?? 0.5),
        )], 201);
    }

    public function complete(Request $request, MediaAsset $mediaAsset, MediaProcessor $processor): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('catalog.update'), 403);
        abort_unless($mediaAsset->created_by === $request->user()->getKey() || $request->user()->hasPermission('users.manage'), 403);

        $asset = $processor->process($mediaAsset);

        return response()->json(['data' => [
            'uuid' => $asset->uuid,
            'status' => $asset->status,
            'mime' => $asset->detected_mime,
            'bytes' => $asset->bytes,
            'width' => $asset->width,
            'height' => $asset->height,
            'sha256' => $asset->sha256,
            'variants' => $asset->variants->map(fn ($variant) => [
                'recipe' => $variant->recipe,
                'width' => $variant->width,
                'height' => $variant->height,
                'sha256' => $variant->sha256,
            ])->values(),
        ]]);
    }
}
