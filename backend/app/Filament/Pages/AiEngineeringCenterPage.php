<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Services\HermesEngineeringService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AiEngineeringCenterPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'AI Management';

    protected static ?string $navigationLabel = 'Hermes Engineering';

    protected static ?string $title = 'Hermes Engineering Center';

    protected static ?string $slug = 'hermes-engineering';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.ai-engineering-center-page';

    public ?array $data = [];

    public ?int $activeReportId = null;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager], true);
    }

    public function mount(HermesEngineeringService $hermes): void
    {
        $this->form->fill([
            'command' => 'system_overview',
            'instruction' => '',
        ]);

        $this->activeReportId = $hermes->recentReports(1)[0]->id ?? null;
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Select::make('command')
                    ->label('Hermes command')
                    ->options(fn (): array => collect(app(HermesEngineeringService::class)->commandOptions())
                        ->mapWithKeys(fn (array $command, string $key): array => [$key => $command['label']])
                        ->all())
                    ->searchable()
                    ->native(false)
                    ->required(),
                Forms\Components\Textarea::make('instruction')
                    ->label('Instruksi tambahan')
                    ->placeholder('Contoh: fokus ke risiko order pending terlalu lama dan rekomendasi query/index.')
                    ->rows(5)
                    ->helperText('Opsional. Hermes tetap berjalan read-only dan hanya menghasilkan laporan/saran.'),
            ]);
    }

    public function runHermes(HermesEngineeringService $hermes): void
    {
        $state = $this->form->getState();
        $result = $hermes->run(
            (string) ($state['command'] ?? 'system_overview'),
            $state['instruction'] ?? null,
            auth()->user(),
        );

        $this->activeReportId = $result['report']?->id;

        Notification::make()
            ->title($result['success'] ? 'Hermes selesai' : 'Hermes gagal')
            ->body($result['message'])
            ->{$result['success'] ? 'success' : 'danger'}()
            ->send();
    }

    public function showReport(int $reportId): void
    {
        $this->activeReportId = $reportId;
    }

    public function getStatus(): array
    {
        return app(HermesEngineeringService::class)->status();
    }

    public function getCommands(): array
    {
        return app(HermesEngineeringService::class)->commandOptions();
    }

    public function getReports(): array
    {
        return app(HermesEngineeringService::class)->recentReports();
    }

    public function getActiveReport(): mixed
    {
        if (! $this->activeReportId) {
            return null;
        }

        return collect($this->getReports())->firstWhere('id', $this->activeReportId);
    }
}
