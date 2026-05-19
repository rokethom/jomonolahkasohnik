<?php

namespace App\Filament\Pages;

use App\Models\PricingKeywordRule;
use App\Services\PricingKeywordRuleService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class PricingLogicReference extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Pricing Management';

    protected static ?string $navigationLabel = 'Pricing Logic Reference';

    protected static ?string $title = 'Pricing Logic Reference';

    protected static ?string $slug = 'pricing-logic-reference';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.pricing-logic-reference';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'service_type' => 'belanja',
            'text' => 'Belikan sayur di depan roxy antar ke rumah customer',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Select::make('service_type')
                    ->label('Service')
                    ->options(PricingKeywordRule::SERVICE_SCOPES)
                    ->default('belanja')
                    ->native(false)
                    ->live(),
                Forms\Components\Textarea::make('text')
                    ->label('Text order / payload text')
                    ->rows(5)
                    ->live(onBlur: false),
            ]);
    }

    public function getRules(): array
    {
        $service = app(PricingKeywordRuleService::class);
        $dbRules = $service->activeRules();

        if ($dbRules->isNotEmpty()) {
            return [
                'mode' => 'database',
                'label' => 'Database Rules Active',
                'rules' => $dbRules->map(fn (PricingKeywordRule $rule): array => [
                    'name' => $rule->name,
                    'keywords' => array_map('trim', explode(',', $rule->keywords)),
                    'amount' => $rule->amount,
                    'service_scopes' => $rule->service_scopes ?: ['all'],
                    'description' => $rule->description ?: 'Rule dari CMS.',
                    'source_fields' => ['destination_text', 'destination_address', 'notes', 'service_payload.store_location', 'items'],
                ])->all(),
            ];
        }

        return [
            'mode' => 'hardcoded_fallback',
            'label' => 'Hardcoded Fallback Active',
            'rules' => $service->hardcodedRules(),
        ];
    }

    public function getPreview(): array
    {
        return app(PricingKeywordRuleService::class)->preview(
            (string) data_get($this->data, 'service_type', 'belanja'),
            (string) data_get($this->data, 'text', ''),
        );
    }
}
