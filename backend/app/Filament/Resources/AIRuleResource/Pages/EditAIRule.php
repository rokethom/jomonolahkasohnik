<?php
declare(strict_types=1);
namespace App\Filament\Resources\AIRuleResource\Pages;
use App\Filament\Resources\AIRuleResource; use Filament\Actions; use Filament\Resources\Pages\EditRecord;
class EditAIRule extends EditRecord { protected static string $resource = AIRuleResource::class; protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; } }
