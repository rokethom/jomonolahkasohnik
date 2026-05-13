<?php

declare(strict_types=1);

namespace App\AI\Agents;

use App\Models\AiRule;
use Illuminate\Support\Facades\Cache;

class RuleEngineAgent
{
    public function activeRules(): array
    {
        return Cache::remember('jojobot:ai_rules:active', 3600, fn (): array => AiRule::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get()
            ->toArray());
    }
}
