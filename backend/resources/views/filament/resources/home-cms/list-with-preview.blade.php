<x-filament-panels::page>
    <style>
        .home-cms-shell {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 380px;
            gap: 18px;
            align-items: start;
        }

        .home-cms-table {
            min-width: 0;
        }

        .home-cms-preview-side {
            position: sticky;
            top: 88px;
        }

        @media (max-width: 1280px) {
            .home-cms-shell {
                grid-template-columns: 1fr;
            }

            .home-cms-preview-side {
                position: static;
            }
        }
    </style>

    <div class="home-cms-shell">
        <div class="home-cms-table">
            {{ $this->table }}
        </div>

        <aside class="home-cms-preview-side">
            @include('filament.resources.home-cms.preview')
        </aside>
    </div>
</x-filament-panels::page>
