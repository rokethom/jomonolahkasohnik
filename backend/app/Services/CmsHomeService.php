<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Banner;
use App\Models\HomeItem;
use App\Models\HomeSection;
use Illuminate\Support\Facades\Cache;

class CmsHomeService
{
    public const CACHE_KEY = 'cms_home_payload_v1';

    public function payload(bool $onlyActive = true): array
    {
        return Cache::remember(self::CACHE_KEY.($onlyActive ? ':active' : ':all'), now()->addMinutes(10), fn (): array => [
            'banners' => $this->banners($onlyActive),
            'banner' => $this->banners($onlyActive),
            'sections' => $this->sections($onlyActive),
            'items' => $this->items($onlyActive),
            'announcements' => $this->announcements($onlyActive),
            'announcement' => $this->announcements($onlyActive),
        ]);
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY.':active');
        Cache::forget(self::CACHE_KEY.':all');
    }

    private function banners(bool $onlyActive): array
    {
        return Banner::query()
            ->when($onlyActive, fn ($query) => $query->activeInRange())
            ->with('media')
            ->orderBy('order')
            ->get()
            ->map(fn (Banner $banner): array => [
                'id' => $banner->id,
                'title' => $banner->title,
                'image' => $banner->image_thumb_url,
                'image_original' => $banner->image_url,
                'link' => $banner->link,
                'is_active' => $banner->is_active,
                'order' => $banner->order,
                'start_date' => $banner->start_date?->toISOString(),
                'end_date' => $banner->end_date?->toISOString(),
            ])->values()->all();
    }

    private function sections(bool $onlyActive): array
    {
        return HomeSection::query()
            ->when($onlyActive, fn ($query) => $query->active())
            ->with(['items' => fn ($query) => $query
                ->when($onlyActive, fn ($query) => $query->activeInRange())
                ->with('media')
                ->orderBy('order')])
            ->orderBy('order')
            ->get()
            ->map(fn (HomeSection $section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'type' => $section->type,
                'is_active' => $section->is_active,
                'order' => $section->order,
                'items' => $section->items->map(fn ($item): array => $this->itemPayload($item))->values()->all(),
            ])->values()->all();
    }

    private function items(bool $onlyActive): array
    {
        return HomeSection::query()
            ->when($onlyActive, fn ($query) => $query->active())
            ->with(['items' => fn ($query) => $query
                ->when($onlyActive, fn ($query) => $query->activeInRange())
                ->with('media')
                ->orderBy('order')])
            ->orderBy('order')
            ->get()
            ->flatMap(fn (HomeSection $section) => $section->items->map(fn ($item): array => $this->itemPayload($item)))
            ->values()
            ->all();
    }

    private function itemPayload(HomeItem $item): array
    {
        return [
            'id' => $item->id,
            'section_id' => $item->section_id,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'image' => $item->image_thumb_url,
            'image_original' => $item->image_url,
            'icon' => $item->icon_url,
            'link' => $item->link,
            'extra_data' => $item->extra_data ?? [],
            'is_active' => $item->is_active,
            'order' => $item->order,
            'start_date' => $item->start_date?->toISOString(),
            'end_date' => $item->end_date?->toISOString(),
        ];
    }

    private function announcements(bool $onlyActive): array
    {
        return Announcement::query()
            ->when($onlyActive, fn ($query) => $query->activeInRange())
            ->latest()
            ->get()
            ->map(fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'content' => $announcement->content,
                'is_active' => $announcement->is_active,
                'start_date' => $announcement->start_date?->toISOString(),
                'end_date' => $announcement->end_date?->toISOString(),
            ])->values()->all();
    }
}
