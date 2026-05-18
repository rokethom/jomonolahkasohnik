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
        <link rel="stylesheet" href="{{ asset('assets/ag-grid/ag-grid.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/ag-grid/ag-theme-quartz.css') }}">
        <script src="{{ asset('assets/ag-grid/ag-grid-community.min.js') }}"></script>
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

            .deposit-grid-shell {
                padding: .75rem;
            }

            .deposit-ag-grid {
                --ag-background-color: #0f172a;
                --ag-foreground-color: #f8fafc;
                --ag-border-color: rgba(148, 163, 184, .24);
                --ag-header-background-color: #111827;
                --ag-header-foreground-color: #f8fafc;
                --ag-odd-row-background-color: #111827;
                --ag-row-hover-color: rgba(37, 99, 235, .18);
                --ag-selected-row-background-color: rgba(14, 165, 233, .18);
                --ag-wrapper-border-radius: 12px;
                height: 66vh;
                min-height: 520px;
                width: 100%;
            }

            .deposit-ag-grid .ag-header {
                min-height: 118px;
            }

            .deposit-ag-grid .ag-header-cell,
            .deposit-ag-grid .ag-header-group-cell {
                align-items: stretch;
                padding-inline: 8px;
            }

            .deposit-ag-grid .ag-header-cell-label {
                align-items: center;
                justify-content: center;
                line-height: 1.2;
                min-height: 70px;
                overflow: visible;
                text-align: center;
                white-space: normal;
            }

            .deposit-ag-grid .ag-header-cell-text {
                line-height: 1.2;
                overflow: visible;
                text-overflow: clip;
                white-space: normal;
                word-break: normal;
            }

            .deposit-ag-grid .ag-floating-filter {
                align-items: center;
                min-height: 42px;
                padding-top: 4px;
            }

            .deposit-ag-grid .ag-header-icon,
            .deposit-ag-grid .ag-sort-indicator-container {
                flex: 0 0 auto;
            }

            .deposit-ag-grid .ag-cell {
                align-items: center;
                display: flex;
                font-size: .78rem;
                font-weight: 650;
            }

            .deposit-ag-grid .deposit-driver-cell {
                font-weight: 850;
            }

            .deposit-ag-grid .deposit-number-cell {
                justify-content: flex-end;
                text-align: right;
            }

            .deposit-ag-grid .deposit-editable-cell {
                background: rgba(14, 165, 233, .12);
                color: #e0f2fe;
            }

            .deposit-ag-grid .deposit-total-cell,
            .deposit-ag-grid .deposit-remaining-cell {
                background: rgba(2, 6, 23, .95);
                color: #ffffff;
                font-weight: 900;
            }

            .deposit-ag-grid .deposit-orange-header {
                background: #7c2d12;
                color: #fed7aa;
            }

            .deposit-ag-grid .deposit-yellow-header {
                background: #713f12;
                color: #fde68a;
            }

            .deposit-grid-note {
                color: #93c5fd;
                font-size: .78rem;
                margin-top: .65rem;
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
        <script>
            window.initDriverDepositReportGrid = function (root, rows, livewire) {
                if (!window.agGrid || !root || !livewire) {
                    return;
                }

                const target = root.querySelector('[data-deposit-grid]');
                if (!target) {
                    return;
                }

                if (root.__depositGridApi) {
                    root.__depositGridApi.destroy();
                }

                const moneyFormatter = (params) => {
                    const value = Number(params.value || 0);
                    return value > 0 ? value.toLocaleString('en-US') : '-';
                };
                const requiredMoneyFormatter = (params) => {
                    const value = Number(params.value || 0);
                    return Number.isFinite(value) ? value.toLocaleString('en-US') : '0';
                };
                const numberFormatter = (params) => Number(params.value || 0).toLocaleString('en-US');
                const moneyParser = (params) => {
                    const parsed = String(params.newValue ?? '').replace(/[^\d-]/g, '');
                    return Math.max(0, Number(parsed || 0));
                };
                const editableNumber = {
                    editable: true,
                    valueParser: moneyParser,
                    valueFormatter: moneyFormatter,
                    cellClass: 'deposit-number-cell deposit-editable-cell',
                    type: 'rightAligned',
                };

                const gridOptions = {
                    rowData: rows,
                    defaultColDef: {
                        sortable: true,
                        filter: true,
                        resizable: true,
                        wrapHeaderText: true,
                        autoHeaderHeight: true,
                        minWidth: 110,
                    },
                    headerHeight: 76,
                    floatingFiltersHeight: 42,
                    columnDefs: [
                        { headerName: 'DRIVER', field: 'driver', pinned: 'left', minWidth: 180, cellClass: 'deposit-driver-cell' },
                        { headerName: 'AREA', field: 'area', pinned: 'left', minWidth: 150 },
                        { headerName: 'JML ORDER BULAN REKAP', field: 'orders_count', width: 135, ...editableNumber, valueFormatter: numberFormatter },
                        { headerName: 'OMSET JASA DASAR BULAN REKAP', field: 'base_service_omset', minWidth: 170, ...editableNumber },
                        { headerName: 'SETORAN 20% BULAN REKAP', field: 'base_service_deposit', minWidth: 170, ...editableNumber },
                        { headerName: 'TAGIHAN BLN LALU', field: 'previous_bill', minWidth: 135, ...editableNumber },
                        { headerName: 'JHT BPJSTK', field: 'bpjs_jht', minWidth: 125, editable: false, valueFormatter: requiredMoneyFormatter, cellClass: 'deposit-number-cell', type: 'rightAligned' },
                        { headerName: 'PREMI BPJSTK', field: 'bpjs', minWidth: 125, editable: false, valueFormatter: requiredMoneyFormatter, cellClass: 'deposit-number-cell', type: 'rightAligned' },
                        { headerName: 'REWARD CASHBACK BULAN LALU', field: 'previous_cashback_reward', minWidth: 170, ...editableNumber, headerClass: 'deposit-orange-header' },
                        { headerName: 'TOTAL TAGIHAN', field: 'bill_before_bansos', minWidth: 130, ...editableNumber },
                        { headerName: 'BANSOS AREA', field: 'bansos', minWidth: 125, ...editableNumber },
                        { headerName: 'TOTAL TAGIHAN BULAN INI', field: 'total_bill', minWidth: 145, ...editableNumber, cellClass: 'deposit-number-cell deposit-editable-cell deposit-total-cell', headerClass: 'deposit-yellow-header' },
                        { headerName: 'TERBAYAR', field: 'paid_amount', minWidth: 130, ...editableNumber },
                        { headerName: 'SISA TAGIHAN', field: 'remaining_bill', minWidth: 135, ...editableNumber, cellClass: 'deposit-number-cell deposit-editable-cell deposit-remaining-cell', headerClass: 'deposit-yellow-header' },
                        { headerName: 'TGL BAYAR (AUTO)', field: 'paid_at', minWidth: 145, editable: false },
                        {
                            headerName: 'STATUS',
                            field: 'status',
                            minWidth: 120,
                            editable: true,
                            cellEditor: 'agSelectCellEditor',
                            cellEditorParams: { values: ['paid', 'unpaid'] },
                            cellClass: 'deposit-editable-cell',
                        },
                        { headerName: 'CASHBACK 10% UTK BULAN DEPAN', field: 'next_cashback', minWidth: 170, ...editableNumber },
                    ],
                    singleClickEdit: false,
                    stopEditingWhenCellsLoseFocus: true,
                    animateRows: true,
                    overlayNoRowsTemplate: '<span style="color:#cbd5e1">Belum ada data setoran pada periode ini.</span>',
                    onCellValueChanged: (event) => {
                        const field = event.colDef.field;
                        if (!field || event.newValue === event.oldValue || !event.data?.driver_id) {
                            return;
                        }

                        event.api.showLoadingOverlay();
                        livewire.updateDepositCell(Number(event.data.driver_id), field, event.newValue)
                            .then((payload) => {
                                event.api.setGridOption('rowData', payload?.rows || []);
                                event.api.hideOverlay();
                            })
                            .catch((error) => {
                                event.data[field] = event.oldValue;
                                event.api.refreshCells({ force: true });
                                event.api.hideOverlay();
                                alert(error?.message || 'Gagal menyimpan perubahan setoran.');
                            });
                    },
                };

                root.__depositGridApi = agGrid.createGrid(target, gridOptions);
            };
        </script>
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
                <div class="text-lg font-bold text-white">Rekap Setoran Driver {{ $this->vehicleType() === 'mobil' ? 'Mobil' : 'Motor' }}</div>
                @php
                    $reportPeriod = \Illuminate\Support\Carbon::create($this->data['year'], $this->data['month'], 1);
                @endphp
                <div class="deposit-period">
                    Periode rekap {{ $reportPeriod->translatedFormat('F Y') }} - data driver memakai username.
                </div>
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

        <div class="deposit-table-card deposit-grid-shell">
            <div
                wire:ignore
                wire:key="driver-deposit-ag-grid-{{ $this->data['year'] }}-{{ $this->data['month'] }}-{{ $this->vehicleType() }}"
                x-data
                x-init="window.initDriverDepositReportGrid($el, @js($rows->values()->all()), $wire)"
            >
                <div data-deposit-grid class="ag-theme-quartz-dark deposit-ag-grid"></div>
                <div class="deposit-grid-note">
                    Double-click sel berwarna biru untuk edit live. Semua nominal bisa diisi manual untuk migrasi data offline; backend tetap menyimpan jejak override manual.
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
