<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;

class AnalyticsAccess
{
    public static function allowed(): bool
    {
        return Gate::allows('viewAnalytics');
    }
}
