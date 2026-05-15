<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\HomeItem;
use App\Models\HomeSection;
use App\Services\CmsHomeService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CmsHomeController extends Controller
{
    public function show(CmsHomeService $cms): JsonResponse
    {
        return response()->json($cms->payload());
    }

    public function admin(CmsHomeService $cms): JsonResponse
    {
        return response()->json(['data' => $cms->payload(false)]);
    }

    public function update(Request $request, CmsHomeService $cms): JsonResponse
    {
        $payload = $request->validate([
            'banners' => ['array'],
            'banners.*.id' => ['nullable', 'integer', 'exists:banners,id'],
            'banners.*.title' => ['required', 'string', 'max:255'],
            'banners.*.link' => ['nullable', 'string', 'max:255'],
            'banners.*.image' => ['nullable', 'string', 'max:255'],
            'banners.*.is_active' => ['boolean'],
            'banners.*.order' => ['integer', 'min:0'],
            'sections' => ['array'],
            'sections.*.id' => ['nullable', 'integer', 'exists:home_sections,id'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.type' => ['required', 'string', 'max:30'],
            'sections.*.is_active' => ['boolean'],
            'sections.*.order' => ['integer', 'min:0'],
            'items' => ['array'],
            'items.*.id' => ['nullable', 'integer', 'exists:home_items,id'],
            'items.*.section_id' => ['required', 'integer', 'exists:home_sections,id'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.subtitle' => ['nullable', 'string', 'max:255'],
            'items.*.link' => ['nullable', 'string', 'max:255'],
            'items.*.image' => ['nullable', 'string', 'max:255'],
            'items.*.icon' => ['nullable', 'string', 'max:255'],
            'items.*.is_active' => ['boolean'],
            'items.*.order' => ['integer', 'min:0'],
            'items.*.start_date' => ['nullable', 'date'],
            'items.*.end_date' => ['nullable', 'date'],
            'announcements' => ['array'],
            'announcements.*.id' => ['nullable', 'integer', 'exists:announcements,id'],
            'announcements.*.title' => ['required', 'string', 'max:255'],
            'announcements.*.content' => ['required', 'string'],
            'announcements.*.is_active' => ['boolean'],
        ]);

        foreach ($payload['banners'] ?? [] as $row) {
            Banner::query()->updateOrCreate(['id' => $row['id'] ?? null], [
                'title' => $row['title'],
                'link' => $row['link'] ?? null,
                'image' => $row['image'] ?? null,
                'is_active' => $row['is_active'] ?? true,
                'order' => $row['order'] ?? 0,
            ]);
        }

        foreach ($payload['sections'] ?? [] as $row) {
            HomeSection::query()->updateOrCreate(['id' => $row['id'] ?? null], [
                'name' => $row['name'],
                'type' => $row['type'],
                'is_active' => $row['is_active'] ?? true,
                'order' => $row['order'] ?? 0,
            ]);
        }

        foreach ($payload['items'] ?? [] as $row) {
            HomeItem::query()->updateOrCreate(['id' => $row['id'] ?? null], [
                'section_id' => $row['section_id'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'] ?? null,
                'link' => $row['link'] ?? null,
                'image' => $row['image'] ?? null,
                'icon' => $row['icon'] ?? null,
                'is_active' => $row['is_active'] ?? true,
                'order' => $row['order'] ?? 0,
                'start_date' => $row['start_date'] ?? null,
                'end_date' => $row['end_date'] ?? null,
            ]);
        }

        foreach ($payload['announcements'] ?? [] as $row) {
            Announcement::query()->updateOrCreate(['id' => $row['id'] ?? null], [
                'title' => $row['title'],
                'content' => $row['content'],
                'is_active' => $row['is_active'] ?? true,
            ]);
        }

        $cms->clearCache();

        return response()->json(['data' => $cms->payload(false)]);
    }

    public function upload(Request $request, ImageService $images): JsonResponse
    {
        $payload = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $path = $images->optimize($payload['image']);

        return response()->json([
            'path' => $path,
            'url' => url('/api/media/'.$path),
        ]);
    }
}
