<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSticker;
use Illuminate\Http\JsonResponse;

class ChatStickerController extends Controller
{
    public function index(): JsonResponse
    {
        $stickers = ChatSticker::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ChatSticker $sticker): array => [
                'id' => $sticker->id,
                'name' => $sticker->name,
                'category' => $sticker->category,
                'image_url' => $sticker->image_url,
            ]);

        return response()->json(['data' => $stickers]);
    }
}
