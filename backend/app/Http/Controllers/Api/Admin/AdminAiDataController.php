<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAliasMap;
use App\Models\AiLocationSuggestion;
use App\Models\AiParserRule;
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\GeojsonRegion;
use App\Models\LocationPoi;
use App\Models\User;
use App\Services\AiLocationLearningService;
use App\Services\OrderTextNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminAiDataController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'branches' => Branch::query()->orderBy('name')->get(['id', 'branch_code', 'name', 'area']),
            'areas' => Schema::hasTable('areas')
                ? Area::query()->with('branch:id,branch_code,name,area')->orderBy('name')->get(['id', 'branch_id', 'code', 'name'])
                : [],
            'regions' => Schema::hasTable('geojson_regions')
                ? GeojsonRegion::query()->active()->with('branch:id,branch_code,name,area')->orderBy('name')->limit(1000)->get(['id', 'branch_id', 'area_id', 'name'])
                : [],
        ]);
    }

    public function parserRules(): JsonResponse
    {
        return response()->json([
            'data' => Schema::hasTable('ai_parser_rules')
                ? AiParserRule::query()->latest('updated_at')->limit(100)->get()
                : [],
        ]);
    }

    public function storeParserRule(Request $request, OrderTextNormalizer $normalizer): JsonResponse
    {
        abort_unless(Schema::hasTable('ai_parser_rules'), 503, 'Tabel AI Parser belum tersedia.');
        $payload = $request->validate($this->parserRuleRules());
        $source = trim((string) ($payload['normalized_text'] ?? '')) ?: (string) $payload['example_text'];
        $payload['normalized_text'] = $normalizer->normalize($source);
        $payload['text_hash'] = hash('sha256', $payload['normalized_text']);
        $rule = AiParserRule::query()->updateOrCreate(['text_hash' => $payload['text_hash']], $payload);
        $this->audit($request->user(), 'saved_ai_parser_rule', $rule);

        return response()->json(['message' => 'AI Parser Memory tersimpan.', 'data' => $rule], $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyParserRule(Request $request, AiParserRule $aiParserRule): JsonResponse
    {
        $this->audit($request->user(), 'deleted_ai_parser_rule', $aiParserRule);
        $aiParserRule->delete();

        return response()->json(['message' => 'AI Parser Memory dihapus.']);
    }

    public function aliasMaps(): JsonResponse
    {
        return response()->json([
            'data' => Schema::hasTable('ai_alias_maps')
                ? AiAliasMap::query()->with(['branch:id,branch_code,name,area', 'area:id,branch_id,code,name', 'geojsonRegion:id,name'])->latest('updated_at')->limit(100)->get()
                : [],
        ]);
    }

    public function storeAliasMap(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('ai_alias_maps') && Schema::hasTable('areas') && Schema::hasTable('geojson_regions'), 503, 'Master data AI Alias Map belum tersedia.');
        $map = AiAliasMap::query()->create($request->validate($this->aliasMapRules()));
        $this->audit($request->user(), 'created_ai_alias_map', $map);

        return response()->json(['message' => 'AI Alias Map tersimpan.', 'data' => $map->load(['branch', 'area', 'geojsonRegion'])], 201);
    }

    public function updateAliasMap(Request $request, AiAliasMap $aiAliasMap): JsonResponse
    {
        abort_unless(Schema::hasTable('areas') && Schema::hasTable('geojson_regions'), 503, 'Master data AI Alias Map belum tersedia.');
        $payload = $request->validate($this->aliasMapRules());
        $aiAliasMap->update($payload);
        $this->audit($request->user(), 'updated_ai_alias_map', $aiAliasMap);

        return response()->json(['message' => 'AI Alias Map diperbarui.', 'data' => $aiAliasMap->fresh(['branch', 'area', 'geojsonRegion'])]);
    }

    public function destroyAliasMap(Request $request, AiAliasMap $aiAliasMap): JsonResponse
    {
        $this->audit($request->user(), 'deleted_ai_alias_map', $aiAliasMap);
        $aiAliasMap->delete();

        return response()->json(['message' => 'AI Alias Map dihapus.']);
    }

    public function locationSuggestions(): JsonResponse
    {
        return response()->json([
            'data' => Schema::hasTable('ai_location_suggestions')
                ? AiLocationSuggestion::query()->with(['branch:id,branch_code,name,area', 'area:id,branch_id,code,name', 'locationPoi:id,name'])->latest('updated_at')->limit(100)->get()
                : [],
        ]);
    }

    public function updateLocationSuggestion(Request $request, AiLocationSuggestion $aiLocationSuggestion): JsonResponse
    {
        abort_unless(Schema::hasTable('areas'), 503, 'Master area belum tersedia.');
        $payload = $request->validate($this->suggestionRules());
        $aiLocationSuggestion->update($payload);
        $this->audit($request->user(), 'updated_ai_location_suggestion', $aiLocationSuggestion);

        return response()->json(['message' => 'Location Learning diperbarui.', 'data' => $aiLocationSuggestion->fresh(['branch', 'area', 'locationPoi'])]);
    }

    public function approveLocationSuggestion(Request $request, AiLocationSuggestion $aiLocationSuggestion, AiLocationLearningService $learning): JsonResponse
    {
        abort_unless(Schema::hasTable('areas') && Schema::hasTable('location_pois'), 503, 'Master POI belum tersedia.');
        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
            'aliases' => ['nullable', 'array', 'max:40'],
            'aliases.*' => ['string', 'max:255'],
            'category' => ['nullable', Rule::in(['pickup', 'destination', 'store', 'school', 'housing', 'market', 'hospital', 'terminal', 'other'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $poi = $learning->approve($aiLocationSuggestion, $payload);
        $this->audit($request->user(), 'approved_ai_location_suggestion', $aiLocationSuggestion, ['location_poi_id' => $poi->id]);

        return response()->json(['message' => 'Suggestion disetujui menjadi Master POI.', 'data' => $aiLocationSuggestion->fresh(['branch', 'area', 'locationPoi'])]);
    }

    public function rejectLocationSuggestion(Request $request, AiLocationSuggestion $aiLocationSuggestion): JsonResponse
    {
        $aiLocationSuggestion->update(['status' => 'rejected']);
        $this->audit($request->user(), 'rejected_ai_location_suggestion', $aiLocationSuggestion);

        return response()->json(['message' => 'Suggestion ditolak.', 'data' => $aiLocationSuggestion->fresh(['branch', 'area', 'locationPoi'])]);
    }

    public function locationPois(): JsonResponse
    {
        return response()->json([
            'data' => Schema::hasTable('location_pois')
                ? LocationPoi::query()->with(['branch:id,branch_code,name,area', 'area:id,branch_id,code,name'])->latest('updated_at')->limit(100)->get()
                : [],
        ]);
    }

    public function storeLocationPoi(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('location_pois') && Schema::hasTable('areas'), 503, 'Master POI belum tersedia.');
        $poi = LocationPoi::query()->create($request->validate($this->locationPoiRules()));
        $this->audit($request->user(), 'created_location_poi', $poi);

        return response()->json(['message' => 'Master Location POI tersimpan.', 'data' => $poi->load(['branch', 'area'])], 201);
    }

    public function updateLocationPoi(Request $request, LocationPoi $locationPoi): JsonResponse
    {
        abort_unless(Schema::hasTable('areas'), 503, 'Master area belum tersedia.');
        $locationPoi->update($request->validate($this->locationPoiRules()));
        $this->audit($request->user(), 'updated_location_poi', $locationPoi);

        return response()->json(['message' => 'Master Location POI diperbarui.', 'data' => $locationPoi->fresh(['branch', 'area'])]);
    }

    public function destroyLocationPoi(Request $request, LocationPoi $locationPoi): JsonResponse
    {
        $this->audit($request->user(), 'deleted_location_poi', $locationPoi);
        $locationPoi->delete();

        return response()->json(['message' => 'Master Location POI dihapus.']);
    }

    private function parserRuleRules(): array
    {
        return [
            'service_type' => ['required', 'string', 'max:30'],
            'normalized_text' => ['nullable', 'string', 'max:5000'],
            'example_text' => ['required', 'string', 'max:10000'],
            'ai_data' => ['required', 'array'],
            'provider' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function aliasMapRules(): array
    {
        return [
            'canonical_name' => ['required', 'string', 'max:255'],
            'aliases' => ['nullable', 'array', 'max:40'],
            'aliases.*' => ['string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
            'geojson_region_id' => ['required', 'exists:geojson_regions,id'],
            'source' => ['required', Rule::in(['manual', 'generated', 'history'])],
            'confidence' => ['required', 'integer', 'min:1', 'max:100'],
            'priority' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function suggestionRules(): array
    {
        return [
            'location_text' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['pickup', 'destination', 'store'])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'aliases' => ['nullable', 'array', 'max:40'],
            'aliases.*' => ['string', 'max:255'],
            'confidence' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ];
    }

    private function locationPoiRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(['pickup', 'destination', 'store', 'school', 'housing', 'market', 'hospital', 'terminal', 'other'])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'aliases' => ['nullable', 'array', 'max:40'],
            'aliases.*' => ['string', 'max:255'],
            'source' => ['required', Rule::in(['manual', 'whatsapp_learning', 'live_price_review', 'dashboard_manual', 'osm_import', 'geojson'])],
            'confidence' => ['required', 'integer', 'min:1', 'max:100'],
            'priority' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function audit(User $actor, string $action, Model $subject, array $metadata = []): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_label' => $subject->getAttribute('name') ?? $subject->getAttribute('canonical_name') ?? $subject->getAttribute('location_text'),
            'metadata' => $metadata,
        ]);
    }
}
