<?php

namespace App\Observers;

use App\Services\CmsHomeService;

class CmsCacheObserver
{
    public function saved(): void
    {
        app(CmsHomeService::class)->clearCache();
    }

    public function deleted(): void
    {
        app(CmsHomeService::class)->clearCache();
    }
}
