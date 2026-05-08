<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\InternalNote;
use App\Models\InternalNoteReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InternalNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeStaff($actor);

        $status = $request->string('status')->toString();
        $category = $request->string('category')->toString();
        $priority = $request->string('priority')->toString();
        $search = $request->string('q')->toString();

        $notes = InternalNote::query()
            ->with(['author:id,name,role,branch_id', 'assignedTo:id,name,role,branch_id', 'branch:id,name,area', 'latestReply.author:id,name,role'])
            ->withCount('replies')
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($category !== '', fn (Builder $query) => $query->where('category', $category))
            ->when($priority !== '', fn (Builder $query) => $query->where('priority', $priority))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%")
                        ->orWhereHas('author', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('assignedTo', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('last_activity_at')
            ->limit(120)
            ->get();

        return response()->json([
            'data' => $notes->map(fn (InternalNote $note): array => $this->notePayload($note))->values(),
            'summary' => $this->summary($actor),
            'options' => [
                'statuses' => ['open', 'in_progress', 'done', 'archived'],
                'priorities' => ['low', 'normal', 'high', 'urgent'],
                'categories' => ['operasional', 'bug', 'ide', 'follow_up', 'customer', 'driver'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeStaff($actor);

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'status' => ['nullable', 'string', 'in:open,in_progress,done,archived'],
            'assigned_to_id' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $note = InternalNote::query()->create([
            'author_id' => $actor->id,
            'assigned_to_id' => $payload['assigned_to_id'] ?? null,
            'branch_id' => $payload['branch_id'] ?? $actor->branch_id,
            'title' => trim($payload['title']),
            'body' => trim($payload['body']),
            'category' => $payload['category'] ?? 'operasional',
            'priority' => $payload['priority'] ?? InternalNote::PRIORITY_NORMAL,
            'status' => $payload['status'] ?? InternalNote::STATUS_OPEN,
        ]);

        return response()->json(['data' => $this->notePayload($note->fresh($this->defaultRelations()))], 201);
    }

    public function update(Request $request, InternalNote $internalNote): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeCanManage($actor, $internalNote);

        $payload = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'body' => ['sometimes', 'required', 'string', 'max:5000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'priority' => ['sometimes', 'nullable', 'string', 'in:low,normal,high,urgent'],
            'status' => ['sometimes', 'nullable', 'string', 'in:open,in_progress,done,archived'],
            'assigned_to_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $internalNote->fill(collect($payload)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->all());
        $internalNote->last_activity_at = now();
        $internalNote->save();

        return response()->json(['data' => $this->notePayload($internalNote->fresh($this->defaultRelations()))]);
    }

    public function reply(Request $request, InternalNote $internalNote): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeStaff($actor);

        $payload = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $reply = DB::transaction(function () use ($internalNote, $actor, $payload): InternalNoteReply {
            if ($internalNote->status === InternalNote::STATUS_ARCHIVED) {
                $internalNote->update(['status' => InternalNote::STATUS_OPEN]);
            }

            return $internalNote->replies()->create([
                'author_id' => $actor->id,
                'body' => trim($payload['body']),
            ])->load('author:id,name,role');
        });

        return response()->json(['data' => $this->replyPayload($reply)], 201);
    }

    public function destroy(Request $request, InternalNote $internalNote): JsonResponse
    {
        $actor = $request->user();

        abort_unless(in_array($actor->role, [UserRole::Admin, UserRole::GM], true), 403, 'Hanya Admin/GM yang bisa menghapus sticky note.');

        $internalNote->delete();

        return response()->json(['success' => true]);
    }

    private function authorizeStaff(User $actor): void
    {
        abort_unless($actor->role instanceof UserRole && $actor->role->isStaff(), 403);
    }

    private function authorizeCanManage(User $actor, InternalNote $note): void
    {
        $this->authorizeStaff($actor);

        abort_unless(
            $actor->id === $note->author_id || in_array($actor->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true),
            403,
            'Anda hanya bisa mengubah note sendiri.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function defaultRelations(): array
    {
        return ['author:id,name,role,branch_id', 'assignedTo:id,name,role,branch_id', 'branch:id,name,area', 'latestReply.author:id,name,role'];
    }

    private function summary(User $actor): array
    {
        $base = InternalNote::query();

        return [
            'open' => (clone $base)->where('status', InternalNote::STATUS_OPEN)->count(),
            'in_progress' => (clone $base)->where('status', InternalNote::STATUS_IN_PROGRESS)->count(),
            'done' => (clone $base)->where('status', InternalNote::STATUS_DONE)->count(),
            'urgent' => (clone $base)->where('priority', InternalNote::PRIORITY_URGENT)->where('status', '!=', InternalNote::STATUS_ARCHIVED)->count(),
            'assigned_to_me' => (clone $base)->where('assigned_to_id', $actor->id)->where('status', '!=', InternalNote::STATUS_ARCHIVED)->count(),
        ];
    }

    private function notePayload(InternalNote $note): array
    {
        return [
            'id' => $note->id,
            'title' => $note->title,
            'body' => $note->body,
            'category' => $note->category,
            'priority' => $note->priority,
            'status' => $note->status,
            'author' => $this->userPayload($note->author),
            'assigned_to' => $this->userPayload($note->assignedTo),
            'branch' => $note->branch ? [
                'id' => $note->branch->id,
                'name' => $note->branch->name,
                'area' => $note->branch->area,
            ] : null,
            'replies_count' => $note->replies_count ?? $note->replies()->count(),
            'latest_reply' => $note->latestReply ? $this->replyPayload($note->latestReply) : null,
            'last_activity_at' => $note->last_activity_at?->toIso8601String(),
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    private function replyPayload(InternalNoteReply $reply): array
    {
        return [
            'id' => $reply->id,
            'note_id' => $reply->internal_note_id,
            'author' => $this->userPayload($reply->author),
            'body' => $reply->body,
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }

    private function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role?->value ?? (string) $user->role,
        ];
    }
}
