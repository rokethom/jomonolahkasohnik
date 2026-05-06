<x-filament-widgets::widget>
    @once
        <style>
            .jojo-activity-grid {
                display: grid;
                gap: 1.25rem;
            }

            .jojo-activity-card {
                background: linear-gradient(135deg, var(--accent-a), var(--accent-b));
                border-radius: 18px;
                box-shadow: 0 22px 50px rgba(2, 6, 23, 0.26);
                padding: 1px;
            }

            .jojo-activity-card__inner {
                background: rgba(15, 23, 42, 0.96);
                border-radius: 17px;
                color: #f8fafc;
                min-height: 100%;
                padding: 1.25rem;
            }

            .jojo-activity-item {
                align-items: center;
                background: rgba(30, 41, 59, 0.84);
                border: 1px solid rgba(148, 163, 184, 0.16);
                border-radius: 14px;
                display: flex;
                gap: 1rem;
                justify-content: space-between;
                padding: 0.85rem 1rem;
                transition: background 160ms ease, border-color 160ms ease;
            }

            .jojo-activity-item:hover {
                background: rgba(51, 65, 85, 0.86);
                border-color: rgba(251, 191, 36, 0.3);
            }

            .jojo-activity-title {
                color: #f8fafc;
                font-weight: 700;
            }

            .jojo-activity-subtitle {
                color: #94a3b8;
                font-size: 0.75rem;
                margin-top: 0.1rem;
            }

            .jojo-activity-badge {
                border-radius: 999px;
                font-size: 0.75rem;
                font-weight: 800;
                padding: 0.35rem 0.7rem;
                white-space: nowrap;
            }

            .jojo-activity-badge--good {
                background: rgba(16, 185, 129, 0.14);
                color: #6ee7b7;
            }

            .jojo-activity-badge--bad {
                background: rgba(248, 113, 113, 0.14);
                color: #fca5a5;
            }

            .jojo-activity-badge--info {
                background: rgba(14, 165, 233, 0.14);
                color: #7dd3fc;
            }

            .jojo-activity-empty {
                border: 1px dashed rgba(148, 163, 184, 0.22);
                border-radius: 14px;
                color: #94a3b8;
                padding: 1rem;
                text-align: center;
            }

            @media (min-width: 1024px) {
                .jojo-activity-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }
        </style>
    @endonce

    <div class="jojo-activity-grid">
        <x-filament::section>
            <div class="jojo-activity-card" style="--accent-a: #7c3aed; --accent-b: #f43f5e;">
                <div class="jojo-activity-card__inner">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">Recent Users</h2>
                            <p class="text-sm text-slate-300">Aktivitas akun terbaru.</p>
                        </div>
                        <x-filament::icon icon="heroicon-o-users" class="h-8 w-8 text-purple-500" />
                    </div>

                    <div class="space-y-3">
                        @forelse ($users as $user)
                            <div class="jojo-activity-item">
                                <div>
                                    <div class="jojo-activity-title">{{ $user->name }}</div>
                                    <div class="jojo-activity-subtitle">{{ $user->username }} &middot; {{ $user->branch?->name ?? 'Global' }}</div>
                                </div>
                                <span @class(['jojo-activity-badge', 'jojo-activity-badge--bad' => $user->is_suspended, 'jojo-activity-badge--good' => ! $user->is_suspended])>
                                    {{ $user->is_suspended ? 'suspended' : 'active' }}
                                </span>
                            </div>
                        @empty
                            <div class="jojo-activity-empty">Belum ada user terbaru.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="jojo-activity-card" style="--accent-a: #06b6d4; --accent-b: #4f46e5;">
                <div class="jojo-activity-card__inner">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">Recent Orders</h2>
                            <p class="text-sm text-slate-300">Order terbaru dari sistem.</p>
                        </div>
                        <x-filament::icon icon="heroicon-o-shopping-bag" class="h-8 w-8 text-cyan-500" />
                    </div>

                    <div class="space-y-3">
                        @forelse ($orders as $order)
                            <div class="jojo-activity-item">
                                <div>
                                    <div class="jojo-activity-title">{{ $order->order_code }}</div>
                                    <div class="jojo-activity-subtitle">{{ $order->user?->name ?? '-' }} &middot; Rp {{ number_format($order->total_price, 0, ',', '.') }}</div>
                                </div>
                                <span class="jojo-activity-badge jojo-activity-badge--info">
                                    {{ $order->status->value ?? $order->status }}
                                </span>
                            </div>
                        @empty
                            <div class="jojo-activity-empty">Belum ada order terbaru.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
