<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\InternalChatMessage;
use App\Models\InternalChatRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InternalChatController extends Controller
{
    public function rooms(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeStaff($actor);
        $this->ensureDefaultRooms($actor);

        $rooms = $this->roomsQuery($actor)
            ->with(['branch', 'participants:id,name,role,branch_id', 'latestMessage.sender:id,name'])
            ->withCount('participants')
            ->latest('updated_at')
            ->get()
            ->map(fn (InternalChatRoom $room): array => $this->roomPayload($room, $actor));

        return response()->json(['data' => $rooms]);
    }

    public function storeRoom(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeStaff($actor);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'in:global,branch,private'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'participant_ids' => ['nullable', 'array', 'max:30'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $type = $payload['type'] ?? 'branch';
        $branchId = $this->roomBranchId($actor, $type, $payload['branch_id'] ?? null);

        $room = DB::transaction(function () use ($actor, $payload, $type, $branchId): InternalChatRoom {
            $room = InternalChatRoom::query()->create([
                'name' => $payload['name'],
                'type' => $type,
                'branch_id' => $branchId,
                'created_by' => $actor->id,
                'is_active' => true,
            ]);

            $participants = collect($payload['participant_ids'] ?? [])
                ->push($actor->id)
                ->unique()
                ->values()
                ->all();

            if ($type === 'private') {
                $this->assertPrivateParticipants($actor, $participants);
            }

            $room->participants()->sync($participants);

            return $room;
        });

        return response()->json(['data' => $this->roomPayload($room->fresh(['branch', 'participants', 'latestMessage.sender']), $actor)], 201);
    }

    public function messages(Request $request, InternalChatRoom $room): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeRoom($actor, $room);
        $this->markRead($room, $actor);

        return response()->json([
            'room' => $this->roomPayload($room->load(['branch', 'participants', 'latestMessage.sender']), $actor),
            'messages' => $room->messages()
                ->with('sender:id,name,role,branch_id')
                ->latest()
                ->limit(80)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (InternalChatMessage $message): array => $this->messagePayload($message)),
        ]);
    }

    public function sendMessage(Request $request, InternalChatRoom $room): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeRoom($actor, $room);

        $payload = $request->validate([
            'message' => ['nullable', 'required_without:attachment', 'string', 'max:4000'],
            'metadata' => ['nullable', 'array'],
            'metadata_json' => ['nullable', 'string', 'max:8000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt'],
            'attachment_source' => ['nullable', 'string', 'in:gallery,camera,document'],
        ]);

        $metadata = $payload['metadata'] ?? [];
        if (isset($payload['metadata_json'])) {
            $decoded = json_decode($payload['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = array_replace_recursive($metadata, $decoded);
            }
        }

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file?->store('internal-chat', 'public');
            $metadata['attachment'] = [
                'source' => $payload['attachment_source'] ?? 'document',
                'name' => $file?->getClientOriginalName(),
                'mime' => $file?->getClientMimeType(),
                'size' => $file?->getSize(),
                'path' => $path,
                'url' => $path ? Storage::url($path) : null,
            ];
        }

        $message = DB::transaction(function () use ($room, $actor, $payload, $metadata): InternalChatMessage {
            if (! $room->participants()->whereKey($actor->id)->exists()) {
                $room->participants()->syncWithoutDetaching([$actor->id => ['last_read_at' => now()]]);
            }

            $message = $room->messages()->create([
                'sender_id' => $actor->id,
                'message' => trim($payload['message'] ?? '') ?: 'Mengirim lampiran',
                'metadata' => $metadata ?: null,
            ]);

            $room->touch();
            $this->markRead($room, $actor);

            return $message->load('sender:id,name,role,branch_id');
        });

        return response()->json(['data' => $this->messagePayload($message)], 201);
    }

    private function roomsQuery(User $actor): Builder
    {
        return InternalChatRoom::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($actor): void {
                if (in_array($actor->role, [UserRole::Admin, UserRole::GM], true)) {
                    $query->whereIn('type', ['global', 'branch', 'private'])
                        ->orWhereHas('participants', fn (Builder $query) => $query->whereKey($actor->id));

                    return;
                }

                $query->where('type', 'global')
                    ->orWhere(function (Builder $query) use ($actor): void {
                        $query->where('type', 'branch')->where('branch_id', $actor->branch_id);
                    })
                    ->orWhereHas('participants', fn (Builder $query) => $query->whereKey($actor->id));
            });
    }

    private function authorizeRoom(User $actor, InternalChatRoom $room): void
    {
        $this->authorizeStaff($actor);

        if (in_array($actor->role, [UserRole::Admin, UserRole::GM], true)) {
            return;
        }

        abort_unless(
            $room->type === 'global'
            || ($room->type === 'branch' && (int) $room->branch_id === (int) $actor->branch_id)
            || $room->participants()->whereKey($actor->id)->exists(),
            403,
            'Room internal di luar akses akun ini.'
        );
    }

    private function authorizeStaff(User $actor): void
    {
        abort_unless($actor->role instanceof UserRole && $actor->role->isStaff(), 403);
    }

    private function ensureDefaultRooms(User $actor): void
    {
        if (in_array($actor->role, [UserRole::Admin, UserRole::GM], true)) {
            InternalChatRoom::query()->firstOrCreate(
                ['type' => 'global', 'branch_id' => null],
                ['name' => 'Jojo Team Global', 'created_by' => $actor->id, 'is_active' => true]
            );
        }

        if ($actor->branch_id) {
            $branch = Branch::query()->find($actor->branch_id);
            $name = $branch ? 'Team '.$branch->name.($branch->area ? ' - '.$branch->area : '') : 'Team Area';

            InternalChatRoom::query()->firstOrCreate(
                ['type' => 'branch', 'branch_id' => $actor->branch_id],
                ['name' => $name, 'created_by' => $actor->id, 'is_active' => true]
            );
        }
    }

    private function roomBranchId(User $actor, string $type, mixed $branchId): ?int
    {
        if ($type === 'global') {
            abort_unless(in_array($actor->role, [UserRole::Admin, UserRole::GM], true), 403, 'Hanya Admin/GM yang bisa membuat room global.');

            return null;
        }

        if (in_array($actor->role, [UserRole::Admin, UserRole::GM], true)) {
            return $branchId ? (int) $branchId : $actor->branch_id;
        }

        return $actor->branch_id;
    }

    private function assertPrivateParticipants(User $actor, array $participantIds): void
    {
        if (in_array($actor->role, [UserRole::Admin, UserRole::GM], true)) {
            return;
        }

        $outsideBranch = User::query()
            ->whereIn('id', $participantIds)
            ->where('branch_id', '!=', $actor->branch_id)
            ->exists();

        abort_if($outsideBranch, 403, 'Private chat hanya untuk user area sendiri.');
    }

    private function markRead(InternalChatRoom $room, User $actor): void
    {
        $room->participants()->syncWithoutDetaching([$actor->id => ['last_read_at' => now()]]);
    }

    private function roomPayload(InternalChatRoom $room, User $viewer): array
    {
        $lastRead = DB::table('internal_chat_participants')
            ->where('internal_chat_room_id', $room->id)
            ->where('user_id', $viewer->id)
            ->value('last_read_at');

        return [
            'id' => $room->id,
            'name' => $room->name,
            'type' => $room->type,
            'branch_id' => $room->branch_id,
            'branch' => $room->branch?->name,
            'branch_area' => $room->branch?->area,
            'participants_count' => $room->participants_count ?? $room->participants->count(),
            'participants' => $room->participants->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role?->value ?? (string) $user->role,
            ])->values(),
            'last_message' => $room->latestMessage?->message,
            'last_sender' => $room->latestMessage?->sender?->name,
            'unread_count' => $room->messages()
                ->when($lastRead, fn (Builder $query) => $query->where('created_at', '>', $lastRead))
                ->where('sender_id', '!=', $viewer->id)
                ->count(),
            'updated_at' => $room->updated_at?->toIso8601String(),
        ];
    }

    private function messagePayload(InternalChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'room_id' => $message->internal_chat_room_id,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name ?? 'System',
            'sender_role' => $message->sender?->role?->value ?? null,
            'message' => $message->message,
            'metadata' => $message->metadata,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
