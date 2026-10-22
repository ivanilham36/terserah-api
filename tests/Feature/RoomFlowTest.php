<?php

namespace Tests\Feature;

use App\Models\Option;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tes tidak boleh memanggil API AI sungguhan: paksa mode fallback
        config(['services.ai.key' => null]);
    }

    private function makeRoom(User $owner, array $overrides = []): Room
    {
        $room = Room::create(array_merge([
            'owner_id' => $owner->id, 'name' => 'Tes', 'category' => 'makanan',
            'code' => 'ABC123', 'status' => 'open',
        ], $overrides));
        $room->members()->attach($owner->id);

        return $room;
    }

    private function makeOption(User $by, string $title, array $extra = []): Option
    {
        return Option::create(array_merge([
            'created_by' => $by->id, 'title' => $title, 'category' => 'makanan', 'price_level' => 1,
        ], $extra));
    }

    public function test_create_room_adds_owner_as_member(): void
    {
        $owner = User::factory()->create();

        $res = $this->actingAs($owner, 'api')->postJson('/api/rooms', ['name' => 'Makan', 'category' => 'makanan'])
            ->assertStatus(201);

        $this->assertSame(6, strlen($res->json('code')));
        $this->assertDatabaseHas('room_members', ['room_id' => $res->json('id'), 'user_id' => $owner->id]);
    }

    public function test_join_with_code_and_unknown_code(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $room = $this->makeRoom($owner);

        $this->actingAs($member, 'api')->postJson('/api/rooms/join', ['code' => 'abc123'])->assertOk();
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $member->id]);

        $this->postJson('/api/rooms/join', ['code' => 'ZZZZZZ'])->assertStatus(404);
    }

    public function test_non_member_cannot_view_room(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $room = $this->makeRoom($owner);

        $this->actingAs($stranger, 'api')->getJson("/api/rooms/{$room->id}")->assertStatus(403);
    }

    public function test_veto_removes_option_from_recommendations(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $room = $this->makeRoom($owner);
        $room->members()->attach($other->id);

        $a = $this->makeOption($owner, 'Opsi A');
        $b = $this->makeOption($owner, 'Opsi B');
        $room->options()->attach([$a->id, $b->id]);

        $this->actingAs($other, 'api')->postJson("/api/rooms/{$room->id}/votes", ['option_id' => $a->id, 'type' => 'veto'])
            ->assertStatus(201);

        $res = $this->actingAs($owner, 'api')->getJson("/api/rooms/{$room->id}/recommendations")->assertOk();

        $this->assertCount(1, $res->json('ranked'));
        $this->assertSame($b->id, $res->json('ranked.0.option_id'));
        $this->assertSame($a->id, $res->json('excluded.0.option_id'));
    }

    public function test_matching_preference_ranks_higher(): void
    {
        $owner = User::factory()->create();
        $room = $this->makeRoom($owner);
        $room->members()->updateExistingPivot($owner->id, ['pref_text' => 'halal']);

        $match = $this->makeOption($owner, 'Nasi Padang', ['tags' => ['halal']]);
        $other = $this->makeOption($owner, 'Menu Lain', ['tags' => ['seafood']]);
        $room->options()->attach([$other->id, $match->id]);

        $res = $this->actingAs($owner, 'api')->getJson("/api/rooms/{$room->id}/recommendations")->assertOk();

        $this->assertSame('Nasi Padang', $res->json('ranked.0.title'));
    }

    public function test_avoided_term_excludes_option(): void
    {
        $owner = User::factory()->create();
        $room = $this->makeRoom($owner);
        $room->members()->updateExistingPivot($owner->id, ['pref_parsed' => json_encode(['likes' => [], 'avoid' => ['pedas']])]);

        $spicy = $this->makeOption($owner, 'Ayam Geprek', ['tags' => ['pedas']]);
        $mild = $this->makeOption($owner, 'Nasi Tim', ['tags' => ['gurih']]);
        $room->options()->attach([$spicy->id, $mild->id]);

        $res = $this->actingAs($owner, 'api')->getJson("/api/rooms/{$room->id}/recommendations")->assertOk();

        $this->assertSame(['Nasi Tim'], array_column($res->json('ranked'), 'title'));
        $this->assertSame($spicy->id, $res->json('excluded.0.option_id'));
    }

    public function test_closer_option_ranks_higher_when_location_is_used(): void
    {
        $owner = User::factory()->create();
        $room = $this->makeRoom($owner, ['use_location' => true]);
        $room->members()->updateExistingPivot($owner->id, ['lat' => -6.2000000, 'lng' => 106.8000000]);

        $far = $this->makeOption($owner, 'Jauh', ['lat' => -6.3000000, 'lng' => 106.9000000]);
        $near = $this->makeOption($owner, 'Dekat', ['lat' => -6.2010000, 'lng' => 106.8010000]);
        $room->options()->attach([$far->id, $near->id]);

        $res = $this->actingAs($owner, 'api')->getJson("/api/rooms/{$room->id}/recommendations")->assertOk();

        $this->assertSame('Dekat', $res->json('ranked.0.title'));
        $this->assertGreaterThan($res->json('ranked.1.score'), $res->json('ranked.0.score'));
    }

    public function test_cannot_vote_for_non_candidate(): void
    {
        $owner = User::factory()->create();
        $room = $this->makeRoom($owner);
        $outsider = $this->makeOption($owner, 'Bukan kandidat');

        $this->actingAs($owner, 'api')->postJson("/api/rooms/{$room->id}/votes", ['option_id' => $outsider->id, 'type' => 'up'])
            ->assertStatus(422);
    }

    public function test_vote_can_be_removed(): void
    {
        $owner = User::factory()->create();
        $room = $this->makeRoom($owner);
        $opt = $this->makeOption($owner, 'A');
        $room->options()->attach($opt->id);

        $this->actingAs($owner, 'api');
        $this->postJson("/api/rooms/{$room->id}/votes", ['option_id' => $opt->id, 'type' => 'up'])->assertStatus(201);
        $this->assertDatabaseCount('votes', 1);

        $this->deleteJson("/api/rooms/{$room->id}/votes/{$opt->id}")->assertOk();
        $this->assertDatabaseCount('votes', 0);
    }

    public function test_only_owner_can_decide_and_room_closes(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $room = $this->makeRoom($owner);
        $room->members()->attach($member->id);
        $opt = $this->makeOption($owner, 'Pilihan');
        $room->options()->attach($opt->id);

        $this->actingAs($member, 'api')->postJson("/api/rooms/{$room->id}/decide", ['option_id' => $opt->id])
            ->assertStatus(403);

        $this->actingAs($owner, 'api')->postJson("/api/rooms/{$room->id}/decide", ['option_id' => $opt->id])
            ->assertOk()->assertJsonPath('status', 'decided');

        $room->refresh();
        $this->assertSame($opt->id, $room->decided_option_id);
        $this->assertNotEmpty($room->decision_reason);

        // ruang yang sudah diputuskan menolak vote baru
        $this->actingAs($owner, 'api')->postJson("/api/rooms/{$room->id}/votes", ['option_id' => $opt->id, 'type' => 'up'])
            ->assertStatus(422);
    }
}
