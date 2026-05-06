<?php

namespace App\Filament\Pages;

use App\Models\KeywordParser;
use Filament\Pages\Page;

class JojoBotFieldSchemaReference extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'JojoBot';

    protected static ?string $navigationLabel = 'Field Schema Reference';

    protected static ?string $title = 'JojoBot Field Schema Reference';

    protected static ?string $slug = 'jojobot-field-schema-reference';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.jojobot-field-schema-reference';

    public function getFieldGroups(): array
    {
        return [
            [
                'title' => 'Lokasi & Rute',
                'description' => 'Field yang dipakai JOJOBOT untuk pickup, tujuan, dan titik tambahan.',
                'tone' => 'sky',
                'fields' => [
                    [
                        'label' => 'Alamat Pembelian',
                        'name' => 'store',
                        'type' => 'text',
                        'aliases' => ['alamat pembelian', 'toko', 'store', 'warung', 'resto', 'pasar', 'lokasi pembelian'],
                        'parser' => 'store_location',
                        'notes' => 'Untuk DO/belanja/gift. Ini lokasi beli/ambil barang. Saat create order, route pickup driver memakai nilai ini, tetapi schema mentahnya tetap store_location agar tidak tertukar dengan alamat jemput customer.',
                    ],
                    [
                        'label' => 'Alamat Jemput',
                        'name' => 'pickup',
                        'type' => 'text',
                        'aliases' => ['jemput', 'pickup', 'asal'],
                        'parser' => 'pickup_address',
                        'notes' => 'Dipakai untuk ojek/kurir/travel sebagai alamat jemput customer/sender. Jangan pakai field ini untuk alamat pembelian.',
                    ],
                    [
                        'label' => 'Alamat Tujuan',
                        'name' => 'destination',
                        'type' => 'text',
                        'aliases' => ['tujuan', 'antar', 'destination', 'alamat'],
                        'parser' => 'destination_address',
                        'notes' => 'Dipakai sebagai alamat tujuan akhir. Untuk DO/belanja, jika user menulis alamat saya/rumah/profile maka isinya adalah alamat profile customer.',
                    ],
                    [
                        'label' => 'Titik Tambahan',
                        'name' => 'point_1',
                        'type' => 'text',
                        'aliases' => ['titik', 'stop', 'mampir'],
                        'parser' => 'points[]',
                        'notes' => 'Dipakai untuk multi titik. FE juga punya tombol + Tambah Titik.',
                    ],
                ],
            ],
            [
                'title' => 'Barang & Belanja',
                'description' => 'Field untuk item belanja, produk, dan catatan order.',
                'tone' => 'amber',
                'fields' => [
                    [
                        'label' => 'List barang',
                        'name' => 'items',
                        'type' => 'textarea',
                        'aliases' => ['barang', 'item', 'produk', 'list', 'belanja'],
                        'parser' => 'notes + items',
                        'notes' => 'Untuk layanan belanja, isian ini juga dibuat menjadi item order.',
                    ],
                    [
                        'label' => 'Item',
                        'name' => 'item',
                        'type' => 'text',
                        'aliases' => ['item', 'barang', 'produk'],
                        'parser' => 'notes + items',
                        'notes' => 'Versi pendek jika admin hanya perlu satu item.',
                    ],
                    [
                        'label' => 'Catatan',
                        'name' => 'notes',
                        'type' => 'textarea',
                        'aliases' => ['catatan', 'notes', 'pesanan'],
                        'parser' => 'notes',
                        'notes' => 'Dipakai untuk instruksi tambahan dan keyword biaya ekstra seperti pasar, gacoan, atau RS.',
                    ],
                ],
            ],
            [
                'title' => 'Customer Profile',
                'description' => 'Field ini otomatis diisi dari profile customer di frontend.',
                'tone' => 'emerald',
                'fields' => [
                    [
                        'label' => 'Nama',
                        'name' => 'name',
                        'type' => 'text',
                        'aliases' => ['nama', 'name'],
                        'parser' => 'customer.name',
                        'notes' => 'FE auto fill dari profile.name.',
                    ],
                    [
                        'label' => 'Phone',
                        'name' => 'phone',
                        'type' => 'phone',
                        'aliases' => ['hp', 'phone', 'telepon', 'whatsapp', 'wa'],
                        'parser' => 'customer.phone',
                        'notes' => 'FE auto fill dari profile.phone.',
                    ],
                    [
                        'label' => 'Alamat',
                        'name' => 'address',
                        'type' => 'textarea',
                        'aliases' => ['alamat', 'address'],
                        'parser' => 'destination_address',
                        'notes' => 'FE auto fill dari profile.address. Lokasi asli tetap dari GPS hidden system.',
                    ],
                ],
            ],
            [
                'title' => 'Travel',
                'description' => 'Field khusus layanan travel.',
                'tone' => 'violet',
                'fields' => [
                    [
                        'label' => 'Rute',
                        'name' => 'route',
                        'type' => 'select',
                        'aliases' => ['rute', 'route'],
                        'parser' => 'route',
                        'notes' => 'Wajib untuk layanan travel agar harga fixed route bisa dihitung.',
                    ],
                    [
                        'label' => 'Tanggal',
                        'name' => 'date',
                        'type' => 'text',
                        'aliases' => ['tanggal', 'date'],
                        'parser' => 'notes',
                        'notes' => 'Saat ini disarankan masuk catatan perjalanan.',
                    ],
                    [
                        'label' => 'Kursi',
                        'name' => 'seat',
                        'type' => 'text',
                        'aliases' => ['kursi', 'seat'],
                        'parser' => 'notes',
                        'notes' => 'Saat ini disarankan masuk catatan perjalanan.',
                    ],
                ],
            ],
        ];
    }

    public function getPresetExamples(): array
    {
        return [
            [
                'title' => 'Belanja / Pasar',
                'service' => 'BL',
                'schema' => [
                    'fields' => [
                        ['label' => 'Lokasi toko', 'name' => 'store', 'type' => 'text', 'required' => true],
                        ['label' => 'List barang', 'name' => 'items', 'type' => 'textarea', 'required' => true],
                        ['label' => 'Catatan', 'name' => 'notes', 'type' => 'textarea', 'required' => false],
                    ],
                ],
            ],
            [
                'title' => 'Kurir / Ojek / Delivery',
                'service' => 'KR / OJ / DO',
                'schema' => [
                    'fields' => [
                        ['label' => 'Alamat Jemput', 'name' => 'pickup', 'type' => 'text', 'required' => true],
                        ['label' => 'Alamat Tujuan', 'name' => 'destination', 'type' => 'text', 'required' => true],
                        ['label' => 'Catatan', 'name' => 'notes', 'type' => 'textarea', 'required' => false],
                    ],
                ],
            ],
            [
                'title' => 'Travel',
                'service' => 'TV',
                'schema' => [
                    'fields' => [
                        ['label' => 'Rute', 'name' => 'route', 'type' => 'select', 'required' => true, 'options' => ['ASB-STB', 'ASB-BWS', 'ASB-JBR', 'STB-BWS', 'STB-JBR', 'BWS-JBR']],
                        ['label' => 'Tanggal', 'name' => 'date', 'type' => 'text', 'required' => true],
                        ['label' => 'Kursi', 'name' => 'seat', 'type' => 'text', 'required' => false],
                    ],
                ],
            ],
        ];
    }

    public function getActiveFormSchemas(): array
    {
        return KeywordParser::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderByRaw("case when parser_type = 'advanced' then 0 else 1 end")
            ->orderBy('keyword')
            ->get(['id', 'keyword', 'service_type', 'parser_type', 'priority', 'form_schema', 'updated_at'])
            ->map(fn (KeywordParser $parser): array => [
                'id' => $parser->id,
                'keyword' => $parser->keyword,
                'service_type' => $parser->service_type,
                'parser_type' => $parser->parser_type,
                'priority' => $parser->priority,
                'updated_at' => $parser->updated_at?->format('d M Y H:i'),
                'schema' => $this->normalizeSchema($parser->form_schema),
            ])
            ->values()
            ->all();
    }

    public function getOrderFlows(): array
    {
        return [
            [
                'service' => 'DO / Belanja / Gift',
                'flow' => [
                    'User mengisi Alamat pembelian',
                    'Parser menyimpan ke store_location',
                    'Payload order memakai pickup_address = store_location',
                    'destination_address = alamat antar/customer/profile',
                ],
                'note' => 'Alamat pembelian bukan alamat jemput customer. Ini titik driver mengambil barang.',
            ],
            [
                'service' => 'Ojek / Kurir',
                'flow' => [
                    'User mengisi Alamat Jemput',
                    'Parser menyimpan ke pickup_address',
                    'User mengisi Alamat Tujuan/Antar',
                    'Payload order memakai destination_address = alamat tujuan',
                ],
                'note' => 'Field pickup_address hanya alamat jemput/sender untuk layanan non-pembelian.',
            ],
            [
                'service' => 'AI Parser',
                'flow' => [
                    'Kimi/OpenAI mengekstrak JSON saja',
                    'purchase_address/store_location dibaca sebagai lokasi pembelian',
                    'pickup_address AI diabaikan sebagai sumber utama untuk layanan pembelian jika store_location tersedia',
                    'Jika AI gagal, parser lama tetap berjalan sebagai fallback',
                ],
                'note' => 'Harga dan create order tetap lewat service Laravel existing, bukan dari AI.',
            ],
        ];
    }

    private function normalizeSchema(mixed $schema): array
    {
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            $schema = is_array($decoded) ? $decoded : [];
        }

        return is_array($schema) ? $schema : [];
    }
}
