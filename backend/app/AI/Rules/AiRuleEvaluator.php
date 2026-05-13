<?php

declare(strict_types=1);

namespace App\AI\Rules;

class AiRuleEvaluator
{
    public function passes(array $rule, array $context): bool
    {
        return ($rule['is_active'] ?? true) === true && $context !== [];
    }
}
