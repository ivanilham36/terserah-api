<?php

namespace Tests\Feature;

use App\Models\Option;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaceAndRoomDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.key' => null]);
        Cache::flush();
    }

    private function room(User $owner, array $o = []): Room
    {
        $room = Room::create(array_merge(['owner_id' => $owner->id, 'name' => 'Tes', 'category' => 'makanan', 'code' => 'ABC123', 'status' => 'open'], $o));
        $room->members()->attach($owner->id);

        return $room;
    }

    private function overpassFake(): array
    {
        return ['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => -6.2010, 'lon' => 106.8170, 'tags' => ['name' => 'Kopi Jauh', 'amenity' => 'cafe', 'internet_access' => 'wlan']],
            ['type' => 'node', 'id' => 2, 'lat' => -6.2001, 'lon' => 106.8161, 'tags' => ['name' => 'Warung Dekat', 'amenity' => 'restaurant', 'cuisine' => 'indonesian', 'diet:halal' => 'yes']],
            ['type' => 'way', 'id' => 3, 'center' => ['lat' => -6.2005, 'lon' => 106.8165], 'tags' => ['amenity' => 'cafe']], // tanpa nama: dibuang
        ]];
    }

    public function test_search_returns_sorted_real_places_with_mapped_tags(): void
    {
        Http::fake(['*overpass*' => Http::response($this->overpassFake())]);
        $u = User::factory()->create();

        $res = $this->actingAs($u, 'api')->getJson('/api/places/search?lat=-6.2&lng=106.816&category=makanan')->assertOk();

        $places = $res->json('places');
        $this->assertCount(2, $places);
        $this->assertSame('Warung Dekat', $places[0]['title']);
        $this->assertSame('node/2', $places[0]['external_id']);
        $this->assertContains('halal', $places[0]['tags']);
        $this->assertContains('wifi', $places[1]['tags']);
        $this->assertLessThan($places[1]['distance_km'], $places[0]['distance_km']);
    }

    public function test_search_by_area_name_uses_geocoder(): void
    {
        Http::fake([
            '*nominatim*' => Http::response([['lat' => '-6.26', 'lon' => '106.81', 'display_name' => 'Kemang, Jakarta']]),
            '*overpass*' => Http::response($this->overpassFake()),
        ]);
        $u = User::factory()->create();

        $res = $this->actingAs($u, 'api')->getJson('/api/places/search?area=Kemang&category=kopi')->assertOk();
        $this->assertSame('Kemang, Jakarta', $res->json('center.name'));
    }

    public function test_search_unknown_area_and_missing_location(): void
    {
        Http::fake(['*nominatim*' => Http::response([])]);
        $u = User::factory()->create();

        $this->actingAs($u, 'api')->getJson('/api/places/search?area=zzzxxyy&category=makanan')->assertStatus(404);
        $this->actingAs($u, 'api')->getJson('/api/places/search?category=makanan')->assertStatus(422);
    }

    public function test_search_falls_back_to_next_mirror(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response('busy', 429),
            'overpass.private.coffee/*' => Http::response($this->overpassFake()),
        ]);
        $u = User::factory()->create();
        $this->actingAs($u, 'api')->getJson('/api/places/search?lat=-6.2&lng=106.8&category=makanan')->assertOk();
    }

    public function test_search_reports_busy_when_all_mirrors_fail(): void
    {
        Http::fake(['*overpass*' => Http::response('down', 504)]);
        $u = User::factory()->create();

        $this->actingAs($u, 'api')->getJson('/api/places/search?lat=-6.3&lng=106.9&category=makanan')->assertStatus(503);
    }

    public function test_search_requires_login(): void
    {
        $this->getJson('/api/places/search?lat=1&lng=1&category=makanan')->assertStatus(401);
    }

    public function test_add_place_to_room_creates_option_once(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $a = $this->room($owner);
        $b = $this->room($other, ['code' => 'XYZ999']);
        $payload = ['external_id' => 'node/2', 'title' => 'Warung Dekat', 'lat' => -6.2001, 'lng' => 106.8161, 'tags' => ['halal'], 'price_level' => 1];

        $this->actingAs($owner, 'api')->postJson("/api/rooms/{$a->id}/places", $payload)->assertStatus(201);
        $this->actingAs($other, 'api')->postJson("/api/rooms/{$b->id}/places", $payload)->assertStatus(201);

        $this->assertSame(1, Option::where('external_id', 'node/2')->count());
        $this->assertSame(1, $a->options()->count());
        $this->assertSame(1, $b->options()->count());
    }

    public function test_add_place_validation_and_membership(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $room = $this->room($owner);

        $this->actingAs($owner, 'api')->postJson("/api/rooms/{$room->id}/places", ['external_id' => 'bogus', 'title' => 'x', 'lat' => 1, 'lng' => 1])->assertStatus(422);
        $this->actingAs($stranger, 'api')->postJson("/api/rooms/{$room->id}/places", ['external_id' => 'node/1', 'title' => 'x', 'lat' => 1, 'lng' => 1])->assertStatus(403);
    }

    public function test_owner_can_delete_room_and_data_cascades(): void
    {
        $owner = User::factory()->create();
        $room = $this->room($owner);
        $opt = Option::create(['created_by' => $owner->id, 'title' => 'A', 'category' => 'makanan', 'price_level' => 1]);
        $room->options()->attach($opt->id);

        $this->actingAs($owner, 'api')->deleteJson("/api/rooms/{$room->id}")->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id]);
        $this->assertDatabaseMissing('room_options', ['room_id' => $room->id]);
        $this->assertDatabaseHas('options', ['id' => $opt->id]); // tempat tetap ada di "Tempat"
    }

    public function test_only_owner_can_delete_and_member_can_leave(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $room = $this->room($owner);
        $room->members()->attach($member->id);

        $this->actingAs($member, 'api')->deleteJson("/api/rooms/{$room->id}")->assertStatus(403);
        $this->actingAs($owner, 'api')->deleteJson("/api/rooms/{$room->id}/members/me")->assertStatus(422);
        $this->actingAs($member, 'api')->deleteJson("/api/rooms/{$room->id}/members/me")->assertOk();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_options_scope_mine(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = Option::create(['created_by' => $me->id, 'title' => 'Punya saya', 'category' => 'makanan', 'price_level' => 1]);
        $used = Option::create(['created_by' => $other->id, 'title' => 'Dipakai di ruang saya', 'category' => 'makanan', 'price_level' => 1]);
        Option::create(['created_by' => $other->id, 'title' => 'Orang lain', 'category' => 'makanan', 'price_level' => 1]);
        $this->room($me)->options()->attach($used->id);

        $titles = collect($this->actingAs($me, 'api')->getJson('/api/options?scope=mine')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Punya saya', 'Dipakai di ruang saya'], $titles);
    }
}
