<?php

namespace App\Services;

use App\Models\KeywordParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class KeywordParserService
{
    public const CACHE_KEY = 'keyword_parsers.active.v1';

    public function detect(string $text): ?array
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->activeParsers() as $parser) {
            if ($this->matches($normalized, (string) $parser['keyword'])) {
                return [
                    'id' => $parser['id'],
                    'keyword' => $parser['keyword'],
                    'service_type' => $parser['service_type'],
                    'response' => $parser['response_template'],
                    'form_schema' => $this->normalizeFormSchema($parser['form_schema'] ?? null),
                    'parser_mode' => $parser['parser_type'],
                    'parser_type' => $parser['parser_type'],
                    'priority' => $parser['priority'],
                ];
            }
        }

        return null;
    }

    public function allActive(): array
    {
        return $this->activeParsers()->values()->all();
    }

    public function preview(array $state, ?string $input = null): array
    {
        $keyword = $this->normalizeKeywordList((string) ($state['keyword'] ?? ''));
        $sample = $input !== null && trim($input) !== '' ? trim($input) : ($keyword ?: 'travel');
        $matched = $keyword !== '' && $this->matches($this->normalize($sample), $keyword);

        return [
            'sample_input' => $sample,
            'matched' => $matched,
            'keyword' => $keyword,
            'service_type' => strtoupper((string) ($state['service_type'] ?? '')),
            'parser_mode' => strtolower((string) ($state['parser_type'] ?? 'simple')),
            'response' => (string) ($state['response_template'] ?? ''),
            'form_schema' => $this->normalizeFormSchema($state['form_schema'] ?? null),
        ];
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function activeParsers(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): Collection => KeywordParser::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderByRaw("case when parser_type = 'advanced' then 0 else 1 end")
            ->orderBy('keyword')
            ->get(['id', 'keyword', 'service_type', 'response_template', 'form_schema', 'parser_type', 'priority'])
            ->map(fn (KeywordParser $parser): array => [
                'id' => $parser->id,
                'keyword' => $parser->keyword,
                'service_type' => $parser->service_type,
                'response_template' => $parser->response_template,
                'form_schema' => $this->normalizeFormSchema($parser->form_schema),
                'parser_type' => $parser->parser_type,
                'priority' => $parser->priority,
            ]));
    }

    private function matches(string $normalizedText, string $keyword): bool
    {
        foreach ($this->keywords($keyword) as $keywordItem) {
            if (preg_match('/(?:^|[^\pL\pN])'.preg_quote($keywordItem, '/').'(?:[^\pL\pN]|$)/u', $normalizedText) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }

    public function normalizeKeywordList(string $keywords): string
    {
        return collect(explode(',', mb_strtolower($keywords)))
            ->map(fn (string $keyword): string => $this->normalize($keyword))
            ->filter()
            ->unique()
            ->implode(', ');
    }

    private function keywords(string $keywords): array
    {
        return collect(explode(',', $keywords))
            ->map(fn (string $keyword): string => $this->normalize($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeFormSchema(mixed $schema): ?array
    {
        if (is_string($schema)) {
            $schema = json_decode($schema, true);
        }

        if (! is_array($schema)) {
            return null;
        }

        $fields = collect($schema['fields'] ?? [])
            ->filter(fn ($field): bool => is_array($field) && filled($field['name'] ?? null) && filled($field['label'] ?? null))
            ->map(fn (array $field): array => [
                'label' => (string) $field['label'],
                'name' => (string) $field['name'],
                'type' => in_array(($field['type'] ?? 'text'), ['text', 'textarea', 'number', 'select', 'phone'], true) ? $field['type'] : 'text',
                'required' => (bool) ($field['required'] ?? false),
                'options' => array_values(array_filter((array) ($field['options'] ?? []), fn ($option): bool => filled($option))),
            ])
            ->values()
            ->all();

        return $fields === [] ? null : ['fields' => $fields];
    }
}
