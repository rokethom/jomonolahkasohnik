<?php

namespace App\Models;

use App\Services\KeywordParserService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KeywordParser extends Model
{
    use HasFactory;

    public const SERVICE_TYPES = [
        'DO' => 'Delivery Order',
        'KR' => 'Kurir',
        'OJ' => 'Ojek',
        'BL' => 'Belanja',
        'TV' => 'Travel',
        'JM' => 'Joker Mobil',
    ];

    public const PARSER_TYPES = [
        'simple' => 'Simple',
        'advanced' => 'Advanced',
    ];

    protected $fillable = [
        'keyword',
        'service_type',
        'response_template',
        'form_schema',
        'parser_type',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'form_schema' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (KeywordParser $parser): void {
            $parser->keyword = app(KeywordParserService::class)->normalizeKeywordList((string) $parser->keyword);
            $parser->service_type = strtoupper(trim($parser->service_type));
            $parser->parser_type = strtolower(trim($parser->parser_type ?: 'simple'));
        });

        static::saved(fn (): mixed => app(KeywordParserService::class)->clearCache());
        static::deleted(fn (): mixed => app(KeywordParserService::class)->clearCache());
    }
}
