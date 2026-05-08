<x-filament-panels::page>
    @php
        $data = $this->roleData();
        $role = $data['role'];
        $menus = $data['menus'];
        $cards = $data['cards'];
        $permissions = $data['permissions'];
        $menuGroups = [
            'Overview' => ['Dashboard', 'Reports'],
            'Operations' => ['Order Operations', 'Request Order', 'Chat Monitor', 'Internal Chat', 'Manual Order'],
            'Management' => ['Users', 'Driver Management'],
            'Area & System' => ['Location Logs', 'Pricing & Policy'],
        ];
        $allMenus = collect($menuGroups)->flatten()->values();
        $hiddenMenus = $allMenus->reject(fn (string $menu): bool => in_array($menu, $menus, true))->values();
    @endphp

    <style>
        .role-preview-shell {
            border-radius: 28px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, .24);
            background:
                radial-gradient(circle at top left, rgba(14, 165, 233, .20), transparent 28rem),
                linear-gradient(135deg, #0f172a 0%, #12243b 52%, #0b1220 100%);
            color: #e5edf8;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .26);
        }

        .role-preview-grid {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            min-height: 720px;
        }

        .role-preview-sidebar {
            background: rgba(2, 6, 23, .70);
            border-right: 1px solid rgba(148, 163, 184, .20);
            padding: 24px 18px;
        }

        .role-preview-main {
            padding: 24px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, .06), transparent 34%),
                rgba(15, 23, 42, .28);
        }

        .role-preview-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 26px;
        }

        .role-preview-avatar {
            width: 44px;
            height: 44px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            font-weight: 800;
            color: #082f49;
            background: linear-gradient(135deg, #facc15, #22c55e);
        }

        .role-preview-group {
            margin-top: 18px;
        }

        .role-preview-group-title {
            color: #8ea4bf;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .10em;
            font-weight: 800;
            margin: 0 0 9px;
        }

        .role-preview-menu {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 11px 13px;
            border-radius: 14px;
            color: #dbeafe;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 7px;
            background: rgba(148, 163, 184, .08);
            border: 1px solid rgba(148, 163, 184, .12);
        }

        .role-preview-menu.is-muted {
            opacity: .34;
            filter: grayscale(.4);
        }

        .role-preview-pill {
            border-radius: 999px;
            padding: 4px 9px;
            color: #93c5fd;
            background: rgba(59, 130, 246, .14);
            font-size: 11px;
            font-weight: 800;
        }

        .role-preview-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
        }

        .role-preview-search {
            min-width: 260px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, .20);
            padding: 10px 16px;
            color: #94a3b8;
            background: rgba(15, 23, 42, .58);
        }

        .role-preview-card-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .role-preview-card,
        .role-preview-panel {
            border-radius: 20px;
            border: 1px solid rgba(148, 163, 184, .18);
            background: rgba(15, 23, 42, .66);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .06);
        }

        .role-preview-card {
            min-height: 118px;
            padding: 18px;
        }

        .role-preview-card span,
        .role-preview-panel small {
            display: block;
            color: #9fb2c8;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .role-preview-card strong {
            display: block;
            margin-top: 13px;
            color: #f8fafc;
            font-size: 25px;
            line-height: 1.1;
        }

        .role-preview-panel-grid {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 16px;
        }

        .role-preview-panel {
            padding: 18px;
        }

        .role-preview-list {
            margin: 14px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .role-preview-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border-radius: 14px;
            padding: 12px 14px;
            background: rgba(30, 41, 59, .74);
            color: #dbeafe;
        }

        .role-preview-note {
            border-radius: 16px;
            padding: 14px;
            background: rgba(14, 165, 233, .12);
            border: 1px solid rgba(14, 165, 233, .22);
            color: #c7e6ff;
        }

        @media (max-width: 1024px) {
            .role-preview-grid,
            .role-preview-panel-grid {
                grid-template-columns: 1fr;
            }

            .role-preview-card-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .role-preview-main,
            .role-preview-sidebar {
                padding: 16px;
            }

            .role-preview-card-grid {
                grid-template-columns: 1fr;
            }

            .role-preview-topbar {
                align-items: stretch;
                flex-direction: column;
            }

            .role-preview-search {
                min-width: 0;
            }
        }
    </style>

    <div class="space-y-6">
        <x-filament::section>
            <div class="grid gap-4 md:grid-cols-[minmax(0,420px)_minmax(0,1fr)] md:items-end">
                <div>
                    {{ $this->form }}
                </div>
                <div class="rounded-2xl border border-sky-200/40 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-400/20 dark:bg-sky-950/40 dark:text-sky-100">
                    Preview ini membaca mapping permission backend dan menu FE Admin. Ini bukan impersonate login, jadi aman untuk cek tampilan role tanpa masuk sebagai HRD, SPV, Manager, Operator, atau Eksekutor.
                </div>
            </div>
        </x-filament::section>

        <div class="role-preview-shell">
            <div class="role-preview-grid">
                <aside class="role-preview-sidebar">
                    <div class="role-preview-brand">
                        <div class="role-preview-avatar">{{ strtoupper(substr($data['label'], 0, 1)) }}</div>
                        <div>
                            <div class="text-base font-extrabold text-white">Jojo Admin</div>
                            <div class="text-xs font-semibold text-slate-400">{{ $data['label'] }} Visual Scope</div>
                        </div>
                    </div>

                    @foreach ($menuGroups as $group => $groupMenus)
                        <div class="role-preview-group">
                            <p class="role-preview-group-title">{{ $group }}</p>
                            @foreach ($groupMenus as $menu)
                                <div class="role-preview-menu {{ in_array($menu, $menus, true) ? '' : 'is-muted' }}">
                                    <span>{{ $menu }}</span>
                                    <span>{{ in_array($menu, $menus, true) ? 'on' : 'off' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </aside>

                <main class="role-preview-main">
                    <div class="role-preview-topbar">
                        <div>
                            <span class="role-preview-pill">{{ $role }}</span>
                            <h2 class="mt-3 text-2xl font-black text-white">{{ $data['label'] }} Dashboard</h2>
                            <p class="mt-1 text-sm font-medium text-slate-300">Simulasi akses visual FE Admin berdasarkan role dan permission aktif.</p>
                        </div>
                        <div class="role-preview-search">Search, auto refresh, theme switch</div>
                    </div>

                    <div class="role-preview-card-grid">
                        @foreach ($cards as $card)
                            <div class="role-preview-card">
                                <span>{{ $card['label'] }}</span>
                                <strong>{{ $card['value'] }}</strong>
                            </div>
                        @endforeach
                    </div>

                    <div class="role-preview-panel-grid">
                        <section class="role-preview-panel">
                            <small>Menu yang tampil</small>
                            <ul class="role-preview-list">
                                @foreach ($menus as $menu)
                                    <li>
                                        <span>{{ $menu }}</span>
                                        <span class="role-preview-pill">visible</span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>

                        <section class="role-preview-panel">
                            <small>Menu disembunyikan</small>
                            <ul class="role-preview-list">
                                @forelse ($hiddenMenus as $menu)
                                    <li>
                                        <span>{{ $menu }}</span>
                                        <span>locked</span>
                                    </li>
                                @empty
                                    <li>
                                        <span>Semua menu FE Admin aktif</span>
                                        <span>full</span>
                                    </li>
                                @endforelse
                            </ul>
                        </section>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <section class="role-preview-panel">
                            <small>Permission aktif</small>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($permissions as $permission)
                                    <span class="role-preview-pill">{{ $permission }}</span>
                                @endforeach
                            </div>
                        </section>

                        <section class="role-preview-panel">
                            <small>Catatan operasional</small>
                            <div class="mt-4 grid gap-3">
                                @foreach ($data['notes'] as $note)
                                    <div class="role-preview-note">{{ $note }}</div>
                                @endforeach
                            </div>
                        </section>
                    </div>
                </main>
            </div>
        </div>
    </div>
</x-filament-panels::page>
