<x-filament-panels::page>
    <style>
        .scc-grid { display: grid; gap: 1rem; }
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
        .scc-table-wrap { overflow:auto; border:1px solid rgba(148,163,184,.25); border-radius:.75rem; }
        .scc-table { width:100%; border-collapse:collapse; min-width:760px; }
        .scc-table th, .scc-table td { padding:.7rem .8rem; border-bottom:1px solid rgba(148,163,184,.18); text-align:left; vertical-align:top; font-size:.88rem; }
        .scc-table th { font-size:.75rem; text-transform:uppercase; color:rgb(100,116,139); letter-spacing:.03em; }
        .dark .scc-table th { color:rgb(148,163,184); }
        .scc-actions { display:flex; flex-wrap:wrap; gap:.5rem; }
        .scc-button { border:1px solid rgba(148,163,184,.35); border-radius:.55rem; padding:.5rem .75rem; font-weight:700; font-size:.82rem; background:white; color:rgb(15,23,42); }
        .dark .scc-button { background:rgba(15,23,42,.6); color:white; }
        .scc-button.primary { background:rgb(245,158,11); color:white; border-color:rgb(245,158,11); }
        .scc-button.danger { background:rgb(239,68,68); color:white; border-color:rgb(239,68,68); }
        .scc-button.success { background:rgb(34,197,94); color:white; border-color:rgb(34,197,94); }
        .scc-textarea { width:100%; min-height:110px; border:1px solid rgba(148,163,184,.35); border-radius:.65rem; padding:.75rem; background:transparent; }
        .scc-badge { display:inline-flex; align-items:center; border-radius:999px; padding:.18rem .5rem; font-size:.72rem; font-weight:700; background:rgba(59,130,246,.12); color:rgb(37,99,235); }
        .scc-badge.danger { background:rgba(239,68,68,.12); color:rgb(220,38,38); }
        .scc-badge.success { background:rgba(34,197,94,.12); color:rgb(22,163,74); }
    </style>

    <div class="scc-grid">
        <div class="scc-cards">
            <div class="scc-card"><span>Failed login hari ini</span><strong>{{ number_format($summary['failed_login_today'] ?? 0) }}</strong></div>
            <div class="scc-card"><span>Staff suspended</span><strong>{{ number_format($summary['suspended_staff'] ?? 0) }}</strong></div>
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
                    <h2>Security Center</h2>
                    <p class="scc-muted">Lock/unlock staff, reset token, revoke session device, audit IP/device login, dan whitelist IP backend.</p>
                </div>
                <button class="scc-button danger" wire:click="revokeAllSessions" wire:confirm="Revoke semua token user selain akun admin aktif? Semua user harus login ulang." type="button">Revoke Semua Token</button>
            </div>

            <div class="scc-table-wrap">
                <table class="scc-table">
                    <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Role</th>
                        <th>Cabang</th>
                        <th>Status</th>
                        <th>Token</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($staffUsers as $staff)
                        <tr>
                            <td><strong>{{ $staff['name'] }}</strong><br><small>{{ $staff['email'] }}</small></td>
                            <td>{{ $staff['role'] }}</td>
                            <td>{{ $staff['branch'] }}</td>
                            <td><span class="scc-badge {{ $staff['is_suspended'] ? 'danger' : 'success' }}">{{ $staff['is_suspended'] ? 'locked' : 'active' }}</span></td>
                            <td>{{ $staff['tokens'] }}</td>
                            <td>
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
                    @endforeach
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
                    <h2>Database Tools</h2>
                    <p class="scc-muted">Backup, restore dari backup lokal, export full data, cleanup log, sinkron branch/area, dan merge duplicate customer.</p>
                </div>
                <div class="scc-actions">
                    <button class="scc-button primary" wire:click="backupDatabase" type="button">Backup Database</button>
                    <button class="scc-button" wire:click="exportFullData" type="button">Export Full Data</button>
                    <button class="scc-button" wire:click="cleanupOldLogs" wire:confirm="Hapus log lama dan failed jobs lama?" type="button">Cleanup Log Lama</button>
                    <button class="scc-button" wire:click="migrateBranchAreaData" wire:confirm="Sinkron ulang data cabang/area dan geofence utama?" type="button">Migrasi Cabang/Area</button>
                    <button class="scc-button danger" wire:click="mergeDuplicateCustomers" wire:confirm="Merge duplicate customer berdasarkan nomor HP? Backup dulu sebelum lanjut." type="button">Merge Duplicate Customer</button>
                </div>
            </div>

            <div class="scc-table-wrap">
                <table class="scc-table">
                    <thead><tr><th>Backup File</th><th>Size</th><th>Updated</th><th>Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($backups as $backup)
                        <tr>
                            <td>{{ $backup['name'] }}</td>
                            <td>{{ $backup['size'] }}</td>
                            <td>{{ $backup['updated_at'] }}</td>
                            <td><button class="scc-button danger" wire:click="restoreDatabase('{{ $backup['name'] }}')" wire:confirm="Restore database dari {{ $backup['name'] }}? Ini aksi berisiko tinggi." type="button">Restore</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Belum ada backup lokal.</td></tr>
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
                <button class="scc-button primary" wire:click="exportAuditLogs" type="button">Export Audit CSV</button>
            </div>

            <div class="scc-table-wrap">
                <table class="scc-table">
                    <thead><tr><th>Waktu</th><th>Actor</th><th>Action</th><th>Subject</th><th>IP / Device</th></tr></thead>
                    <tbody>
                    @foreach ([...$failedLogins, ...$forensicLogs] as $log)
                        <tr>
                            <td>{{ $log['time'] }}</td>
                            <td>{{ $log['actor'] }}</td>
                            <td><span class="scc-badge">{{ $log['action'] }}</span></td>
                            <td>{{ $log['subject'] }}</td>
                            <td><small>{{ $log['ip'] ?: '-' }}<br>{{ $log['device'] ?: '-' }}</small></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
