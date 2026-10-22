<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Vote;
use App\Services\AiService;
use App\Services\ScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    public function index()
    {
        $rooms = auth('api')->user()->rooms()->with('owner:id,name')->latest('rooms.created_at')->get();

        return response()->json($rooms);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'category' => 'required|string|max:50',
            'use_location' => 'boolean',
            'weights' => 'nullable|array',
            'weights.*' => 'numeric|min:0|max:1',
        ]);

        $room = Room::create($data + [
            'owner_id' => auth('api')->id(),
            'code' => $this->newCode(),
            'status' => 'open',
        ]);
        $room->members()->attach(auth('api')->id());

        return response()->json($room, 201);
    }

    public function join(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $room = Room::where('code', strtoupper($request->code))->first();
        if (! $room) {
            return response()->json(['message' => 'Kode ruang tidak ditemukan'], 404);
        }

        $room->members()->syncWithoutDetaching([auth('api')->id()]);

        return response()->json($room);
    }

    public function show(Room $room)
    {
        $this->ensureMember($room);

        $room->load([
            'owner:id,name',
            'members:id,name',
            'options' => fn ($q) => $q->withAvg('reviews', 'rating'),
            'votes',
            'decidedOption',
        ]);

        return response()->json($room);
    }

    public function updateMe(Request $request, Room $room, AiService $ai)
    {
        $this->ensureMember($room);

        $data = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'budget_max' => 'nullable|integer|min:0',
            'pref_text' => 'nullable|string|max:500',
        ]);

        if (! empty($data['pref_text'])) {
            $data['pref_parsed'] = json_encode($ai->parsePreference($data['pref_text'], $room->id));
        } elseif (array_key_exists('pref_text', $data)) {
            $data['pref_parsed'] = null;
        }

        $room->members()->updateExistingPivot(auth('api')->id(), $data);

        return response()->json(['message' => 'Preferensi disimpan']);
    }

    public function addOption(Request $request, Room $room)
    {
        $this->ensureMember($room);
        $this->ensureOpen($room);

        $data = $request->validate(['option_id' => 'required|exists:options,id']);
        $room->options()->syncWithoutDetaching([$data['option_id']]);

        return response()->json(['message' => 'Kandidat ditambahkan'], 201);
    }

    public function removeOption(Room $room, int $optionId)
    {
        $this->ensureMember($room);
        $this->ensureOpen($room);

        $room->options()->detach($optionId);

        return response()->json(['message' => 'Kandidat dihapus']);
    }

    public function vote(Request $request, Room $room)
    {
        $this->ensureMember($room);
        $this->ensureOpen($room);

        $data = $request->validate([
            'option_id' => 'required|integer',
            'type' => 'required|in:up,veto',
        ]);

        if (! $room->options()->where('options.id', $data['option_id'])->exists()) {
            return response()->json(['message' => 'Opsi bukan kandidat di ruang ini'], 422);
        }

        $vote = Vote::updateOrCreate(
            ['room_id' => $room->id, 'user_id' => auth('api')->id(), 'option_id' => $data['option_id']],
            ['type' => $data['type']]
        );

        return response()->json($vote, 201);
    }

    public function unvote(Room $room, int $optionId)
    {
        $this->ensureMember($room);
        $this->ensureOpen($room);

        Vote::where('room_id', $room->id)
            ->where('user_id', auth('api')->id())
            ->where('option_id', $optionId)
            ->delete();

        return response()->json(['message' => 'Suara dicabut']);
    }

    public function recommendations(Room $room, ScoringService $scoring)
    {
        $this->ensureMember($room);

        return response()->json($scoring->rank($room));
    }

    public function decide(Request $request, Room $room, AiService $ai, ScoringService $scoring)
    {
        if ($room->owner_id !== auth('api')->id()) {
            return response()->json(['message' => 'Hanya pemilik ruang yang bisa memutuskan'], 403);
        }
        $this->ensureOpen($room);

        $data = $request->validate(['option_id' => 'required|integer']);
        if (! $room->options()->where('options.id', $data['option_id'])->exists()) {
            return response()->json(['message' => 'Opsi bukan kandidat di ruang ini'], 422);
        }

        $reason = $ai->explainDecision($room, (int) $data['option_id'], $scoring->rank($room));
        $room->update(['decided_option_id' => $data['option_id'], 'status' => 'decided', 'decision_reason' => $reason]);

        return response()->json($room->load('decidedOption'));
    }

    public function suggest(Request $request, Room $room, AiService $ai)
    {
        $this->ensureMember($room);

        $data = $request->validate(['topic' => 'nullable|string|max:200', 'count' => 'nullable|integer|between:1,8']);
        $items = $ai->suggestOptions($room, $data['topic'] ?? '', (int) ($data['count'] ?? 5));

        return response()->json([
            'suggestions' => $items,
            'message' => $items ? 'Ide dari AI (belum disimpan sebagai opsi)' : 'AI sedang tidak tersedia, coba lagi nanti',
        ]);
    }

    private function ensureMember(Room $room): void
    {
        if (! $room->members()->where('users.id', auth('api')->id())->exists()) {
            abort(403, 'Kamu bukan anggota ruang ini');
        }
    }

    private function ensureOpen(Room $room): void
    {
        if ($room->status !== 'open') {
            abort(422, 'Ruang sudah diputuskan');
        }
    }

    private function newCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Room::where('code', $code)->exists());

        return $code;
    }
}


