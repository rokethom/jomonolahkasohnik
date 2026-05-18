<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Services\BranchAccessSettingService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static ?int $navigationSort = 7;

    public static function shouldRegisterNavigation(): bool
    {
        return Schema::hasTable('audit_logs')
            && auth()->user()?->role instanceof UserRole
            && ! in_array(auth()->user()?->role, [UserRole::WebAdmin, UserRole::CmsEditor, UserRole::Driver, UserRole::Customer], true);
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopedQuery();
    }

    public static function scopedQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with('user')
            ->where('action', 'not like', 'system_control_%')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('subject_label')
                    ->orWhere('subject_label', 'not like', '%System Control Center%');
            })
            ->latest();
        $user = auth()->user();

        if (! $user || app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($user->role)) {
            return $query;
        }

        return $query->whereHas('user', fn (Builder $query) => $query->where('branch_id', $user->branch_id));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Actor')
                    ->placeholder('System')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof UserRole ? $state->value : (string) ($state ?? '-')),
                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state): string => class_basename((string) $state))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('subject_label')
                    ->label('Label')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('metadata_summary')
                    ->label('Ringkasan')
                    ->state(fn (AuditLog $record): string => static::metadataSummary($record))
                    ->limit(90)
                    ->tooltip(fn (AuditLog $record): string => static::metadataJson($record))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Action')
                    ->options(fn (): array => AuditLog::query()
                        ->where('action', 'not like', 'system_control_%')
                        ->select('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => static::canDeleteAny()),
                ]),
            ]);
    }

    public static function metadataSummary(AuditLog $record): string
    {
        $metadata = $record->metadata ?? [];

        if ($record->action === 'assigned_driver_to_order') {
            $actor = (string) ($metadata['assigned_by_name'] ?? $record->user?->name ?? 'System');
            $driver = (string) ($metadata['assigned_driver_name'] ?? $metadata['assigned_driver_username'] ?? $metadata['driver_id'] ?? 'driver');
            $username = (string) ($metadata['assigned_driver_username'] ?? '');
            $order = (string) ($metadata['order_code'] ?? $record->subject_label ?? ('#'.$record->subject_id));
            $area = (string) ($metadata['area_name'] ?? $metadata['branch_name'] ?? '');
            $reason = (string) ($metadata['reason'] ?? '');

            return trim($actor.' menugaskan '.$driver.($username !== '' ? ' (@'.$username.')' : '').' ke '.$order.($area !== '' ? ' - '.$area : '').($reason !== '' ? '. Alasan: '.$reason : ''));
        }

        if ($record->action === 'broadcast_pending_order_to_drivers') {
            $actor = (string) ($metadata['broadcast_by_name'] ?? $record->user?->name ?? 'System');
            $order = (string) ($metadata['order_code'] ?? $record->subject_label ?? ('#'.$record->subject_id));
            $count = (int) ($metadata['driver_count'] ?? 0);
            $targets = collect($metadata['driver_targets'] ?? [])
                ->map(fn ($target): string => is_array($target) ? (string) ($target['name'] ?? $target['username'] ?? $target['driver_id'] ?? '') : '')
                ->filter()
                ->take(5)
                ->implode(', ');

            return trim($actor.' broadcast '.$order.' ke '.$count.' driver'.($targets !== '' ? ': '.$targets.($count > 5 ? ', ...' : '') : ''));
        }

        return static::metadataJson($record);
    }

    public static function metadataJson(AuditLog $record): string
    {
        return json_encode($record->metadata ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
