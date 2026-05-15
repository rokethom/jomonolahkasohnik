<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $headers = $this->headers();
        $summary = [
            'drivers' => $rows->count(),
            'orders' => $rows->sum('orders_count'),
            'omset' => $rows->sum('base_service_omset'),
            'tagihan' => $rows->sum('total_bill'),
            'terbayar' => $rows->sum('paid_amount'),
            'sisa' => $rows->sum('remaining_bill'),
        ];
    @endphp

    @once
        <style>
            .deposit-report-shell {
                color: #e5e7eb;
            }

            .deposit-filter-card,
            .deposit-summary-card,
            .deposit-table-card {
                background: linear-gradient(180deg, rgba(24, 24, 27, 0.98), rgba(15, 23, 42, 0.94));
                border: 1px solid rgba(148, 163, 184, 0.18);
                border-radius: 14px;
                box-shadow: 0 18px 40px rgba(2, 6, 23, 0.22);
            }

            .deposit-summary-grid {
                display: grid;
                gap: .85rem;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .deposit-summary-card {
                padding: 1rem;
            }

            .deposit-summary-label {
                color: #94a3b8;
                font-size: .74rem;
                font-weight: 750;
                text-transform: uppercase;
            }

            .deposit-summary-value {
                color: #f8fafc;
                font-size: 1.1rem;
                font-weight: 850;
                margin-top: .2rem;
            }

            .deposit-action-row {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: .75rem;
                justify-content: space-between;
            }

            .deposit-period {
                color: #cbd5e1;
                font-size: .86rem;
            }

            .deposit-table-scroll {
                overflow: auto;
                max-height: 64vh;
            }

            .deposit-table {
                border-collapse: separate;
                border-spacing: 0;
                min-width: 1460px;
                width: 100%;
            }

            .deposit-table th {
                background: #111827;
                border-bottom: 1px solid rgba(148, 163, 184, .28);
                border-right: 1px solid rgba(148, 163, 184, .18);
                color: #f8fafc;
                font-size: .68rem;
                font-weight: 850;
                line-height: 1.2;
                padding: .85rem .7rem;
                position: sticky;
                text-align: center;
                text-transform: uppercase;
                top: 0;
                vertical-align: middle;
                white-space: normal;
                z-index: 2;
            }

            .deposit-table th.deposit-accent-orange {
                background: #7c2d12;
                color: #fed7aa;
            }

            .deposit-table th.deposit-accent-yellow {
                background: #713f12;
                color: #fde68a;
            }

            .deposit-table td {
                background: #f8fafc;
                border-bottom: 1px solid #dbe3ef;
                border-right: 1px solid #e2e8f0;
                color: #111827 !important;
                font-size: .78rem;
                font-weight: 650;
                padding: .75rem .7rem;
                vertical-align: middle;
            }

            .deposit-table tbody tr:nth-child(even) td {
                background: #eef2f7;
            }

            .deposit-table tbody tr:hover td {
                background: #dbeafe;
            }

            .deposit-table .deposit-name {
                color: #0f172a !important;
                font-weight: 850;
                min-width: 150px;
            }

            .deposit-table .deposit-area {
                color: #334155 !important;
                min-width: 140px;
            }

            .deposit-table .deposit-number {
                text-align: right;
                white-space: nowrap;
            }

            .deposit-table .deposit-total {
                background: #0f172a !important;
                color: #ffffff !important;
                font-weight: 900;
            }

            .deposit-table .deposit-remaining {
                background: #1e293b !important;
                color: #ffffff !important;
                font-weight: 900;
            }

            .deposit-empty {
                background: #f8fafc !important;
                color: #475569 !important;
                font-size: .95rem !important;
                padding: 2rem !important;
                text-align: center;
            }

            @media (min-width: 768px) {
                .deposit-summary-grid {
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                }
            }

            @media (min-width: 1280px) {
                .deposit-summary-grid {
                    grid-template-columns: repeat(6, minmax(0, 1fr));
                }
            }
        </style>
    @endonce

    <div class="deposit-report-shell space-y-6">
        <x-filament-actions::modals />

        <div class="deposit-filter-card p-4">
            {{ $this->form }}
        </div>

        <div class="deposit-summary-grid">
            <div class="deposit-summary-card">
                <div class="deposit-summary-label">Driver</div>
                <div class="deposit-summary-value">{{ number_format($summary['drivers'], 0, ',', '.') }}</div>
            </div>
            <div class="deposit-summary-card">
                <div class="deposit-summary-label">Order</div>
                <div class="deposit-summary-value">{{ number_format($summary['orders'], 0, ',', '.') }}</div>
            </div>
            <div class="deposit-summary-card">
                <div class="deposit-summary-label">Omset</div>
                <div class="deposit-summary-value">Rp {{ number_format($summary['omset'], 0, ',', '.') }}</div>
            </div>
            <div class="deposit-summary-card">
                <div class="deposit-summary-label">Tagihan</div>
                <div class="deposit-summary-value">Rp {{ number_format($summary['tagihan'], 0, ',', '.') }}</div>
            </div>
            <div class="deposit-summary-card">
                <div class="deposit-summary-label">Terbayar</div>
                <div class="deposit-summary-value">Rp {{ number_format($summary['terbayar'], 0, ',', '.') }}</div>
            </div>
            <div class="deposit-summary-card">
                <div class="deposit-summary-label">Sisa</div>
                <div class="deposit-summary-value">Rp {{ number_format($summary['sisa'], 0, ',', '.') }}</div>
            </div>
        </div>

        <div class="deposit-action-row">
            <div>
                <div class="text-lg font-bold text-white">Rekap Setoran Driver</div>
                <div class="deposit-period">Periode {{ \Illuminate\Support\Carbon::create($this->data['year'], $this->data['month'], 1)->translatedFormat('F Y') }}</div>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-filament::button wire:click="mountAction('importDeposit')" color="warning" icon="heroicon-o-arrow-up-tray">
                    Import Update Setoran
                </x-filament::button>
                <x-filament::button wire:click="downloadTemplateCsv" color="gray" icon="heroicon-o-document-arrow-down">
                    Download Template CSV
                </x-filament::button>
                <x-filament::button wire:click="downloadTemplateExcel" color="gray" icon="heroicon-o-table-cells">
                    Download Template XLS
                </x-filament::button>
                <x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray">
                    Export CSV
                </x-filament::button>
                <x-filament::button wire:click="exportExcel" color="success" icon="heroicon-o-table-cells">
                    Export Excel
                </x-filament::button>
            </div>
        </div>

        <div class="deposit-table-card">
            <div class="deposit-table-scroll">
            <table class="deposit-table">
                <thead>
                    <tr>
                        @foreach ($headers as $index => $header)
                            <th @class([
                                'deposit-accent-orange' => $index === 8,
                                'deposit-accent-yellow' => in_array($index, [11, 13], true),
                            ])>
                                {{ $header }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="deposit-name">{{ $row['driver'] }}</td>
                            <td class="deposit-area">{{ $row['area'] }}</td>
                            <td class="deposit-number">{{ $row['orders_count'] }}</td>
                            <td class="deposit-number">{{ number_format($row['base_service_omset'], 0, '.', ',') }}</td>
                            <td class="deposit-number">{{ number_format($row['base_service_deposit'], 0, '.', ',') }}</td>
                            <td class="deposit-number">{{ $row['previous_bill'] ? number_format($row['previous_bill'], 0, '.', ',') : '-' }}</td>
                            <td class="deposit-number">{{ $row['bpjs_jht'] ? number_format($row['bpjs_jht'], 0, '.', ',') : '-' }}</td>
                            <td class="deposit-number">{{ $row['bpjs'] ? number_format($row['bpjs'], 0, '.', ',') : '-' }}</td>
                            <td class="deposit-number">{{ $row['previous_cashback_reward'] ? number_format($row['previous_cashback_reward'], 0, '.', ',') : '-' }}</td>
                            <td class="deposit-number">{{ number_format($row['bill_before_bansos'], 0, '.', ',') }}</td>
                            <td class="deposit-number">{{ $row['bansos'] ? number_format($row['bansos'], 0, '.', ',') : '-' }}</td>
                            <td class="deposit-number deposit-total">{{ number_format($row['total_bill'], 0, '.', ',') }}</td>
                            <td class="deposit-number">{{ $row['paid_amount'] ? number_format($row['paid_amount'], 0, '.', ',') : '-' }}</td>
                            <td class="deposit-number deposit-remaining">{{ $row['remaining_bill'] ? number_format($row['remaining_bill'], 0, '.', ',') : '-' }}</td>
                            <td>{{ $row['paid_at'] ?: '-' }}</td>
                            <td class="deposit-number">{{ number_format($row['next_cashback'], 0, '.', ',') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="16" class="deposit-empty">Belum ada data setoran pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
