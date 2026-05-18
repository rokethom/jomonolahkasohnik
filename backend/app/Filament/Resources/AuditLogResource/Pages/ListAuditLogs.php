<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use App\Models\AuditLog;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_xls')
                ->label('Export XLS')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->downloadAuditLogs()),
        ];
    }

    private function downloadAuditLogs(): StreamedResponse
    {
        $filename = 'audit-logs-'.now()->format('Ymd-His').'.xls';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Waktu', 'Actor', 'Role', 'Action', 'Subject', 'Subject ID', 'Label', 'Ringkasan', 'Metadata'], "\t");

            AuditLogResource::scopedQuery()
                ->limit(5000)
                ->get()
                ->each(function (AuditLog $log) use ($handle): void {
                    fputcsv($handle, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        $log->user?->name ?? 'System',
                        $log->user?->role?->value ?? '-',
                        $log->action,
                        class_basename($log->subject_type),
                        $log->subject_id,
                        $log->subject_label,
                        AuditLogResource::metadataSummary($log),
                        AuditLogResource::metadataJson($log),
                    ], "\t");
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }
}
