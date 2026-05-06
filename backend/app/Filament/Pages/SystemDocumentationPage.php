<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use Filament\Pages\Page;

class SystemDocumentationPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Dokumentasi';

    protected static ?string $navigationLabel = 'Flow Sistem';

    protected static ?string $title = 'Dokumentasi Flow Sistem JOJO';

    protected static ?string $slug = 'system-documentation';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.system-documentation-page';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true);
    }
}
