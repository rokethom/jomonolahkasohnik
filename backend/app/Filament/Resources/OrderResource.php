<?php

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('monitor_live_order') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Order')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('order_code')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Select::make('status')
                            ->options(self::statusOptions())
                            ->required(),
                        Forms\Components\Select::make('user_id')
                            ->label('Customer')
                            ->options(fn (): array => User::query()
                                ->where('role', 'customer')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('driver_id')
                            ->label('Driver')
                            ->options(fn (): array => Driver::query()
                                ->with('user')
                                ->get()
                                ->mapWithKeys(fn (Driver $driver): array => [
                                    $driver->id => $driver->user?->name ?? 'Driver #'.$driver->id,
                                ])
                                ->all())
                            ->searchable(),
                        Forms\Components\TextInput::make('service_type')
                            ->required()
                            ->maxLength(50),
                        Forms\Components\TextInput::make('service_code')
                            ->maxLength(10),
                        Forms\Components\TextInput::make('source')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Route')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('pickup_address')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('pickup_lat')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('pickup_lng')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('destination_address')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('destination_lat')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('destination_lng')
                            ->numeric()
                            ->required(),
                    ]),
                Forms\Components\Section::make('Pricing')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('price')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('service_charge')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('total_price')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_code')
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['class' => 'sticky left-0 bg-white dark:bg-gray-900 z-10'], merge: true)
                    ->extraHeaderAttributes(['class' => 'sticky left-0 bg-white dark:bg-gray-900 z-20'], merge: true)
                    ->extraCellAttributes(['class' => 'sticky left-0 bg-white dark:bg-gray-900 z-10'], merge: true),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver.user.name')
                    ->label('Driver')
                    ->placeholder('-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('service_type')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('service_code')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus|string|null $state): string => self::statusValue($state))
                    ->color(fn (OrderStatus|string|null $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('pickup_address')
                    ->limit(24)
                    ->searchable(),
                Tables\Columns\TextColumn::make('destination_address')
                    ->limit(24)
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_price')
                    ->money('IDR')
                    ->sortable()
                    ->extraAttributes(['class' => 'sticky right-0 bg-white dark:bg-gray-900 z-10'], merge: true)
                    ->extraHeaderAttributes(['class' => 'sticky right-0 bg-white dark:bg-gray-900 z-20'], merge: true)
                    ->extraCellAttributes(['class' => 'sticky right-0 bg-white dark:bg-gray-900 z-10'], merge: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('service_type'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'driver.user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    private static function statusOptions(): array
    {
        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->value])
            ->all();
    }

    private static function statusValue(OrderStatus|string|null $state): string
    {
        if ($state instanceof OrderStatus) {
            return $state->value;
        }

        return filled($state) ? (string) $state : '-';
    }

    private static function statusColor(OrderStatus|string|null $state): string
    {
        $status = $state instanceof OrderStatus
            ? $state
            : OrderStatus::tryFrom((string) $state);

        return match ($status) {
            OrderStatus::Created => 'gray',
            OrderStatus::SearchingDriver,
            OrderStatus::PendingCancel => 'warning',
            OrderStatus::DriverAccepted,
            OrderStatus::DriverOnTheWay,
            OrderStatus::ArrivedPickup,
            OrderStatus::OnGoing => 'info',
            OrderStatus::Completed => 'success',
            OrderStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }
}
