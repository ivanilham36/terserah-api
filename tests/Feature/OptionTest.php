<?php

namespace Tests\Feature;

use App\Models\Option;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionTest extends TestCase
{
    use RefreshDatabase;

    private function makeOption(User $by, array $overrides = []): Option
    {
        return Option::create(array_merge([
            'created_by' => $by->id, 'title' => 'Bakso', 'category' => 'makanan', 'price_level' => 1,
        ], $overrides));
    }

    public function test_options_require_authentication(): void
    {
        $this->getJson('/api/options')->assertStatus(401);
    }

    public function test_user_can_create_option(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/api/options', [
            'title' => 'Bakso', 'category' => 'makanan', 'price_level' => 1, 'tags' => ['bakso', 'halal'],
        ])->assertCreated()->assertJsonPath('title', 'Bakso');

        $this->assertDatabaseHas('options', ['title' => 'Bakso', 'created_by' => $user->id]);
    }

    public function test_create_option_validates_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/api/options', ['title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'category', 'price_level']);
    }

    public function test_only_creator_or_admin_can_delete(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $option = $this->makeOption($owner);

        $this->actingAs($other, 'api')->deleteJson("/api/options/{$option->id}")->assertStatus(403);
        $this->assertDatabaseHas('options', ['id' => $option->id]);

        $this->actingAs($admin, 'api')->deleteJson("/api/options/{$option->id}")->assertOk();
        $this->assertDatabaseMissing('options', ['id' => $option->id]);
    }

    public function test_index_filters_by_category_and_search(): void
    {
        $user = User::factory()->create();
        $this->makeOption($user, ['title' => 'Nasi Goreng', 'category' => 'makanan']);
        $this->makeOption($user, ['title' => 'Film Komedi', 'category' => 'film']);

        $this->actingAs($user, 'api');

        $this->getJson('/api/options?category=film')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/options?q=Nasi')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_review_is_updated_instead_of_duplicated(): void
    {
        $user = User::factory()->create();
        $option = $this->makeOption($user);

        $this->actingAs($user, 'api');
        $this->postJson("/api/options/{$option->id}/reviews", ['rating' => 3])->assertStatus(201);
        $this->postJson("/api/options/{$option->id}/reviews", ['rating' => 5])->assertStatus(201);

        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', ['option_id' => $option->id, 'rating' => 5]);
    }

    public function test_review_rating_must_be_between_1_and_5(): void
    {
        $user = User::factory()->create();
        $option = $this->makeOption($user);

        $this->actingAs($user, 'api')->postJson("/api/options/{$option->id}/reviews", ['rating' => 9])
            ->assertStatus(422)->assertJsonValidationErrors(['rating']);
    }
}
