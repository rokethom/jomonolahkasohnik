<x-filament-panels::page>
    <style>
        .scc-grid { display: grid; gap: 1rem; }
        .fi-main, .fi-page, .fi-page-content { min-width: 0; }
        .scc-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .85rem; }
        .scc-card, .scc-panel { border: 1px solid rgba(148,163,184,.25); background: rgba(15,23,42,.04); border-radius: .75rem; padding: 1rem; }
        .dark .scc-card, .dark .scc-panel { background: rgba(15,23,42,.45); }
        .scc-card span, .scc-muted { color: rgb(100,116,139); font-size: .82rem; }
        .dark .scc-card span, .dark .scc-muted { color: rgb(148,163,184); }
        .scc-card strong { display: block; font-size: 1.6rem; line-height: 1.15; margin-top: .25rem; }
        .scc-section-title { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:.85rem; }
        .scc-section-title h2 { font-size:1.05rem; font-weight:700; margin:0; }
        .scc-health { display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:.65rem; }
        .scc-health-item { border:1px solid rgba(148,163,184,.22); border-radius:.65rem; padding:.8rem; }
        .scc-health-item.ok { border-color: rgba(34,197,94,.4); }
        .scc-health-item.bad { border-color: rgba(239,68,68,.45); }
        .scc-health-item b { display:block; font-size:.92rem; }
        .scc-health-item small { color:rgb(100,116,139); word-break:break-word; }
        .dark .scc-health-item small { color:rgb(148,163,184); }
        .scc-table-wrap { max-height:430px; overflow:auto; border:1px solid rgba(148,163,184,.25); border-radius:.75rem; scrollbar-width: thin; scrollbar-color: rgba(148,163,184,.75) transparent; }
        .scc-table-wrap::-webkit-scrollbar, .scc-doc-grid::-webkit-scrollbar { width: 10px; height: 10px; }
        .scc-table-wrap::-webkit-scrollbar-thumb, .scc-doc-grid::-webkit-scrollbar-thumb { background: rgba(148,163,184,.75); border-radius: 999px; }
        .scc-table-wrap::-webkit-scrollbar-track, .scc-doc-grid::-webkit-scrollbar-track { background: rgba(148,163,184,.12); }
        .scc-table { width:100%; border-collapse:collapse; min-width:760px; }
        .scc-table th, .scc-table td { padding:.7rem .8rem; border-bottom:1px solid rgba(148,163,184,.18); text-align:left; vertical-align:top; font-size:.88rem; }
        .scc-table th { position:sticky; top:0; z-index:2; background:rgb(248,250,252); font-size:.75rem; text-transform:uppercase; color:rgb(100,116,139); letter-spacing:.03em; }
        .dark .scc-table th { background:rgb(15,23,42); color:rgb(148,163,184); }
        .scc-table tbody tr:hover { background: rgba(59,130,246,.06); }
        .scc-actions { display:flex; flex-wrap:wrap; gap:.5rem; }
        .scc-button { border:1px solid rgba(148,163,184,.35); border-radius:.55rem; padding:.5rem .75rem; font-weight:700; font-size:.82rem; background:white; color:rgb(15,23,42); }
        .dark .scc-button { background:rgba(15,23,42,.6); color:white; }
        .scc-button.primary { background:rgb(245,158,11); color:white; border-color:rgb(245,158,11); }
        .scc-button.danger { background:rgb(239,68,68); color:white; border-color:rgb(239,68,68); }
        .scc-button.success { background:rgb(34,197,94); color:white; border-color:rgb(34,197,94); }
        .scc-textarea { width:100%; min-height:110px; border:1px solid rgba(148,163,184,.35); border-radius:.65rem; padding:.75rem; background:transparent; }
        .scc-search { width:min(100%, 360px); border:1px solid rgba(148,163,184,.35); border-radius:.65rem; padding:.58rem .75rem; background:transparent; }
        .scc-badge { display:inline-flex; align-items:center; border-radius:999px; padding:.18rem .5rem; font-size:.72rem; font-weight:700; background:rgba(59,130,246,.12); color:rgb(37,99,235); }
        .scc-badge.danger { background:rgba(239,68,68,.12); color:rgb(220,38,38); }
        .scc-badge.success { background:rgba(34,197,94,.12); color:rgb(22,163,74); }
        .scc-doc-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:.8rem; max-height:520px; overflow:auto; padding-right:.2rem; scrollbar-width: thin; scrollbar-color: rgba(148,163,184,.75) transparent; }
        .scc-doc-card { border:1px solid rgba(148,163,184,.22); border-radius:.75rem; padding:.85rem; background:rgba(15,23,42,.03); }
        .dark .scc-doc-card { background:rgba(15,23,42,.32); }
        .scc-doc-card h3 { margin:0 0 .35rem; font-size:.98rem; font-weight:800; }
        .scc-doc-card p { margin:.25rem 0; color:rgb(100,116,139); font-size:.82rem; line-height:1.45; }
        .dark .scc-doc-card p { color:rgb(148,163,184); }
        .scc-doc-card ul { margin:.45rem 0 0; padding-left:1.1rem; color:rgb(71,85,105); font-size:.82rem; line-height:1.5; }
        .dark .scc-doc-card ul { color:rgb(203,213,225); }
        .scc-code { display:inline-block; max-width:100%; border-radius:.4rem; padding:.12rem .35rem; background:rgba(15,23,42,.08); font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:.75rem; overflow-wrap:anywhere; }
        .dark .scc-code { background:rgba(255,255,255,.08); }
        @media (max-width: 640px) {
            .scc-grid, .scc-panel, .scc-card, .scc-table-wrap { min-width: 0; max-width: 100%; }
            .scc-cards, .scc-health { grid-template-columns: 1fr; }
            .scc-card, .scc-panel { padding: .85rem; border-radius: .65rem; }
            .scc-card strong { font-size: 1.35rem; }
            .scc-section-title { display: block; }
            .scc-section-title h2 { font-size: 1rem; }
            .scc-section-title .scc-actions { margin-top: .8rem; }
            .scc-actions { display: grid; grid-template-columns: 1fr; width: 100%; }
            .scc-button, .scc-search { width: 100%; min-height: 2.55rem; }
            .scc-table-wrap { max-height: 65vh; overflow: auto; border: 1px solid rgba(148,163,184,.25); border-radius: .75rem; padding: .6rem; }
            .scc-table { display: block; min-width: 0; width: 100%; }
            .scc-table thead { display: none; }
            .scc-table tbody, .scc-table tr, .scc-table td { display: block; width: 100%; }
            .scc-table tr {
                border: 1px solid rgba(148,163,184,.25);
                border-radius: .75rem;
                padding: .2rem .75rem;
                margin-bottom: .75rem;
                background: rgba(15,23,42,.03);
            }
            .dark .scc-table tr { background: rgba(15,23,42,.35); }
            .scc-table td {
                border-bottom: 1px solid rgba(148,163,184,.14);
                padding: .65rem 0;
                font-size: .86rem;
                overflow-wrap: anywhere;
            }
            .scc-table td:last-child { border-bottom: 0; }
            .scc-table td::before {
                content: attr(data-label);
                display: block;
                margin-bottom: .25rem;
                color: rgb(100,116,139);
                font-size: .7rem;
                font-weight: 800;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .dark .scc-table td::before { color: rgb(148,163,184); }
            .scc-table td .scc-actions { margin-top: .35rem; }
            .scc-textarea { min-height: 130px; }
            .scc-doc-grid { grid-template-columns: 1fr; }
        }
    </style>

    <div class="scc-grid">
        <div class="scc-cards">
            <div class="scc-card"><span>Failed login hari ini</span><strong>{{ number_format($summary['failed_login_today'] ?? 0) }}</strong></div>
            <div class="scc-card"><span>Akun locked</span><strong>{{ number_format($summary['suspended_staff'] ?? 0) }}</strong></div>
            <div class="scc-card"><span>Active API tokens</span><strong>{{ number_format($summary['active_tokens'] ?? 0) }}</strong></div>
            <div class="scc-card"><span>Failed jobs</span><strong>{{ number_format($summary['failed_jobs'] ?? 0) }}</strong></div>
            <div class="scc-card"><span>Audit logs</span><strong>{{ number_format($summary['audit_logs'] ?? 0) }}</strong></div>
        </div>

        <section class="scc-panel">
            <div class="scc-section-title">
                <div>
                    <h2>Deployment & Maintenance</h2>
                    <p class="scc-muted">Health check backend, Redis, database, storage, OSRM, queue, dan build FE.</p>
                </div>
                <div class="scc-actions">
                    <button class="scc-button" wire:click="refreshDashboard" type="button">Refresh</button>
                    <a class="scc-button success" href="{{ url('/admin/horizon') }}" target="_blank" rel="noopener">Horizon Queue</a>
                    <button class="scc-button primary" wire:click="clearLaravelCache" wire:confirm="Clear cache Laravel sekarang?" type="button">Clear Cache</button>
                    <button class="scc-button" wire:click="runStorageLink" wire:confirm="Jalankan php artisan storage:link?" type="button">Storage Link</button>
                    <button class="scc-button danger" wire:click="restartSupervisor" wire:confirm="Restart supervisor/worker sekarang? Gunakan saat maintenance saja." type="button">Restart Worker</button>
                </div>
            </div>

            <div class="scc-health">
                @foreach ($health as $key => $item)
                    @if ($key === 'builds')
                        @foreach ($item as $build)
                            <div class="scc-health-item {{ $build['ok'] ? 'ok' : 'bad' }}">
                                <b>{{ $build['label'] }}</b>
                                <small>{{ $build['detail'] }}</small>
                            </div>
                        @endforeach
                    @else
                        <div class="scc-health-item {{ $item['ok'] ? 'ok' : 'bad' }}">
                            <b>{{ $item['label'] }}</b>
                            <small>{{ $item['detail'] }}</small>
                        </div>
                    @endif
                @endforeach
            </div>
        </section>

        <section class="scc-panel">
            <div class="scc-section-title">
                <div>
                    <h2>Branding Aplikasi</h2>
                    <p class="scc-muted">Khusus admin: ubah nama, logo, icon browser, dan icon PWA untuk customer, driver, admin frontend, serta nama backend Filament.</p>
                </div>
            </div>

            {{ $this->brandingForm }}

            <button class="scc-button primary" style="margin-top:1rem" wire:click="saveBranding" type="button">Simpan Branding</button>
        </section>

        <section class="scc-panel">
            <div class="scc-section-title">
                <div>
                    <h2>Security Center</h2>
                    <p class="scc-muted">Lock/unlock staff dan customer, reset token, revoke session device, audit IP/device login, dan whitelist IP backend.</p>
                </div>
                <div class="scc-actions">
                    <input class="scc-search" type="search" wire:model.live.debounce.500ms="securitySearch" placeholder="Cari nama, email, HP, role, cabang...">
                    <button class="scc-button danger" wire:click="revokeAllSessions" wire:confirm="Revoke semua token user selain akun admin aktif? Semua user harus login ulang." type="button">Revoke Semua Token</button>
                </div>
            </div>

            <div class="scc-table-wrap">
                <table class="scc-table">
                    <thead>
                    <tr>
                        <th>Akun</th>
                        <th>Role</th>
                        <th>Cabang</th>
                        <th>Status</th>
                        <th>Token</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($staffUsers as $staff)
                        <tr>
                            <td data-label="Akun">
                                <strong>{{ $staff['name'] }}</strong><br>
                                <small>{{ $staff['email'] ?: '-' }}</small><br>
                                <small>{{ $staff['phone'] ?: $staff['username'] ?: '-' }}</small>
                            </td>
                            <td data-label="Role">{{ $staff['role'] }}</td>
                            <td data-label="Cabang">{{ $staff['branch'] }}</td>
                            <td data-label="Status"><span class="scc-badge {{ $staff['is_suspended'] ? 'danger' : 'success' }}">{{ $staff['is_suspended'] ? 'locked' : 'active' }}</span></td>
                            <td data-label="Token">{{ $staff['tokens'] }}</td>
                            <td data-label="Aksi">
                                <div class="scc-actions">
                                    @if ($staff['is_suspended'])
                                        <button class="scc-button success" wire:click="unlockStaff({{ $staff['id'] }})" wire:confirm="Unlock {{ $staff['name'] }}?" type="button">Unlock</button>
                                    @else
                                        <button class="scc-button danger" wire:click="lockStaff({{ $staff['id'] }})" wire:confirm="Lock {{ $staff['name'] }} dan cabut token?" type="button">Lock</button>
                                    @endif
                                    <button class="scc-button" wire:click="resetUserTokens({{ $staff['id'] }})" wire:confirm="Reset semua token {{ $staff['name'] }}?" type="button">Reset Token</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td data-label="Data" colspan="6">Tidak ada akun yang cocok dengan pencarian.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem">
                <label>
                    <strong>Whitelist IP backend</strong>
                    <textarea class="scc-textarea" wire:model.defer="whitelistIps" placeholder="1 IP per baris"></textarea>
                </label>
                <button class="scc-button primary" style="margin-top:.5rem" wire:click="saveWhitelistIps" type="button">Simpan Whitelist IP</button>
            </div>
        </section>

        <section class="scc-panel">
            <div class="scc-section-title">
                <div>
                    <h2>Role Capability Documentation</h2>
                    <p class="scc-muted">Dokumentasi readonly: setiap role bisa apa saja, scope aksesnya, dan batasannya. Ini membantu admin mengecek hak akses tanpa membuka kode.</p>
                </div>
            </div>

            <div class="scc-doc-grid">
                @foreach ($roleDocumentation as $roleDoc)
                    <article class="scc-doc-card">
                        <h3>{{ $roleDoc['role'] }}</h3>
                        <p><strong>{{ $roleDoc['level'] }}</strong></p>
                        <p>{{ $roleDoc['scope'] }}</p>
                        <p><strong>Bisa:</strong></p>
                        <ul>
                            @foreach ($roleDoc['can'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                        <p><strong>Batasan:</strong></p>
                        <ul>
                            @foreach ($roleDoc['cannot'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="scc-panel">
            <div class="scc-section-title">
                <div>
                    <h2>Dokumentasi Sistem & API Docs</h2>
                    <p class="scc-muted">Ringkasan file dokumentasi internal dan opsi generator OpenAPI Scramble/dedoc.</p>
                </div>
            </div>

            <div class="scc-doc-grid">
                @foreach ($systemDocumentation as $doc)
                    <article class="scc-doc-card">
                        <h3>{{ $doc['title'] }}</h3>
                        <p>{{ $doc['summary'] }}</p>
                        <p><span class="scc-code">{{ $doc['path'] }}</span></p>
                    </article>
                @endforeach
                <article class="scc-doc-card">
                    <h3>Scramble / dedoc OpenAPI</h3>
                    <p><strong>Status:</strong> {{ $apiDocumentationNote['status'] }}</p>
                    <p>{{ $apiDocumentationNote['recommendation'] }}</p>
                    <p><strong>Flow aman:</strong> {{ $apiDocumentationNote['safe_flow'] }}</p>
                    <p><span class="scc-code">{{ $apiDocumentationNote['routes'] }}</span></p>
                </article>
            </div>
        </section>

        <section class="scc-panel">
            <div class="scc-section-title">
                <div>
                    <h2>Database Tools</h2>
                    <p class="scc-muted">Backup, restore dari backup lokal, export full data, cleanup log, sinkron branch/area, dan merge duplicate customer.</p>
                </div>
                <div class="scc-actions">
                    <button class="scc-button primary" wire:click="backupDatabase" type="button">Backup Database</button>
                    <button class="scc-button success" wire:click="backupAndDownloadDatabase" type="button">Backup & Download SQL</button>
                    <button class="scc-button" wire:click="exportFullData" type="button">Export Full Data</button>
                    <button class="scc-button" wire:click="cleanupOldLogs" wire:confirm="Hapus log lama dan failed jobs lama?" type="button">Cleanup Log Lama</button>
                    <button class="scc-button" wire:click="migrateBranchAreaData" wire:confirm="Sinkron ulang data cabang/area dan geofence utama?" type="button">Migrasi Cabang/Area</button>
                    <button class="scc-button danger" wire:click="mergeDuplicateCustomers" wire:confirm="Merge duplicate customer berdasarkan nomor HP? Backup dulu sebelum lanjut." type="button">Merge Duplicate Customer</button>
                </div>
            </div>

            <div class="scc-panel" style="margin-bottom:1rem">
                <div class="scc-section-title">
                    <div>
                        <h2>Auto Backup Database</h2>
                        <p class="scc-muted">Scheduler Laravel mengecek setiap 5 menit. File SQL dibuat maksimal satu kali per hari sesuai jam yang dipilih.</p>
                    </div>
                    <span class="scc-badge {{ $autoBackupEnabled ? 'success' : 'danger' }}">
                        {{ $autoBackupEnabled ? 'Aktif' : 'Nonaktif' }}
                    </span>
                </div>

                <div class="scc-health" style="margin-bottom:.85rem">
                    <div class="scc-health-item {{ $latestAutoBackup ? 'ok' : 'bad' }}">
                        <b>Backup otomatis terakhir</b>
                        <small>
                            @if ($latestAutoBackup)
                                {{ $latestAutoBackup['name'] }} / {{ $latestAutoBackup['updated_at'] }} / {{ $latestAutoBackup['size'] }}
                            @else
                                Belum ada auto backup.
                            @endif
                        </small>
                    </div>
                    <div class="scc-health-item ok">
                        <b>Jadwal aktif</b>
                        <small>{{ $autoBackupTime }} WIB / retensi {{ $autoBackupRetentionDays }} hari</small>
                    </div>
                </div>

                <div class="scc-actions" style="align-items:end">
                    <label class="scc-muted" style="display:flex;align-items:center;gap:.45rem;min-height:2.55rem">
                        <input type="checkbox" wire:model.live="autoBackupEnabled">
                        Aktifkan auto backup
                    </label>
                    <label>
                        <span class="scc-muted">Jam backup</span><br>
                        <input class="scc-search" type="time" wire:model.defer="autoBackupTime">
                    </label>
                    <label>
                        <span class="scc-muted">Retensi hari</span><br>
                        <input class="scc-search" type="number" min="1" max="180" wire:model.defer="autoBackupRetentionDays">
                    </label>
                    <button class="scc-button primary" wire:click="saveAutoBackupSettings" type="button">Simpan Auto Backup</button>
                    <button class="scc-button" wire:click="runAutoBackupNow" wire:confirm="Jalankan test auto backup sekarang?" type="button">Test Backup Sekarang</button>
                </div>
            </div>

            <div class="scc-table-wrap">
                <table class="scc-table">
                    <thead><tr><th>Backup File</th><th>Size</th><th>Updated</th><th>Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($backups as $backup)
                        <tr>
                            <td data-label="Backup File">{{ $backup['name'] }}</td>
                            <td data-label="Size">{{ $backup['size'] }}</td>
                            <td data-label="Updated">{{ $backup['updated_at'] }}</td>
                            <td data-label="Aksi">
                                <div class="scc-actions">
                                    <button class="scc-button" wire:click="downloadDatabaseBackup('{{ $backup['name'] }}')" type="button">Download</button>
                                    <button class="scc-button danger" wire:click="restoreDatabase('{{ $backup['name'] }}')" wire:confirm="Restore database dari {{ $backup['name'] }}? Ini aksi berisiko tinggi." type="button">Restore</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td data-label="Data" colspan="4">
                                Belum ada backup lokal.
                                <div class="scc-actions" style="margin-top:.6rem">
                                    <button class="scc-button success" wire:click="backupAndDownloadDatabase" type="button">Buat & Download Backup</button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="scc-panel">
            <div class="scc-section-title">
                <div>
                    <h2>Audit Log & Forensic</h2>
                    <p class="scc-muted">Melacak perubahan harga, suspend driver, delete order, update setoran, login gagal, dan aksi sistem.</p>
                </div>
                <div class="scc-actions">
                    <input class="scc-search" type="search" wire:model.live.debounce.500ms="auditSearch" placeholder="Cari actor, action, subject, IP/device...">
                    <button class="scc-button primary" wire:click="exportAuditLogs" type="button">Export Audit CSV</button>
                </div>
            </div>

            <div class="scc-table-wrap">
                <table class="scc-table">
                    <thead><tr><th>Waktu</th><th>Actor</th><th>Action</th><th>Subject</th><th>IP / Device</th></tr></thead>
                    <tbody>
                    @forelse (array_merge($failedLogins, $forensicLogs) as $log)
                        <tr>
                            <td data-label="Waktu">{{ $log['time'] }}</td>
                            <td data-label="Actor">{{ $log['actor'] }}</td>
                            <td data-label="Action"><span class="scc-badge">{{ $log['action'] }}</span></td>
                            <td data-label="Subject">{{ $log['subject'] }}</td>
                            <td data-label="IP / Device"><small>{{ $log['ip'] ?: '-' }}<br>{{ $log['device'] ?: '-' }}</small></td>
                        </tr>
                    @empty
                        <tr><td data-label="Data" colspan="5">Tidak ada audit log yang cocok dengan pencarian.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
