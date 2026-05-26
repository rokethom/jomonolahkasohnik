<?php

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;

trait RequiresAiDataAccess
{
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('manage_ai_data') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canEdit(Model $record): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canDelete(Model $record): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canDeleteAny(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
