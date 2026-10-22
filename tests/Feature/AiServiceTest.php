<?php

namespace Tests\Feature;

use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_keyword_parser_without_api_key(): void
    {
        config(['services.ai.key' => null]);

        $result = app(AiService::class)->parsePreference('pengen yang halal dan murah, tapi nggak mau pedas');

        $this->assertContains('halal', $result['likes']);
        $this->assertContains('murah', $result['likes']);
        $this->assertNotContains('tapi', $result['likes']);
        $this->assertSame(['pedas'], $result['avoid']);
        $this->assertDatabaseHas('ai_logs', ['type' => 'parse_pref', 'status' => 'fallback']);
    }

    public function test_uses_ai_json_even_when_wrapped_in_code_fence(): void
    {
        config(['services.ai.key' => 'test', 'services.ai.models' => 'model-a']);
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => "```json\n{\"likes\":[\"Halal\",\"Murah\"],\"avoid\":[\"pedas\"]}\n```"]]],
        ], 200)]);

        $result = app(AiService::class)->parsePreference('apa saja');

        $this->assertSame(['halal', 'murah'], $result['likes']);
        $this->assertSame(['pedas'], $result['avoid']);
        $this->assertDatabaseHas('ai_logs', ['type' => 'parse_pref', 'status' => 'ok']);
    }

    public function test_switches_to_next_model_when_first_is_rate_limited(): void
    {
        config(['services.ai.key' => 'test', 'services.ai.models' => 'model-a,model-b']);
        Http::fake(function ($request) {
            if ($request['model'] === 'model-a') {
                return Http::response(['error' => 'rate limit'], 429);
            }

            return Http::response(['choices' => [['message' => ['content' => '{"likes":["sate"],"avoid":[]}']]]], 200);
        });

        $result = app(AiService::class)->parsePreference('sate');

        $this->assertSame(['sate'], $result['likes']);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('ai_logs', ['type' => 'parse_pref', 'status' => 'ok']);
    }

    public function test_all_models_failing_falls_back_without_exception(): void
    {
        config(['services.ai.key' => 'test', 'services.ai.models' => 'model-a,model-b']);
        Http::fake(['*' => Http::response([], 500)]);

        $result = app(AiService::class)->parsePreference('suka kopi');

        $this->assertContains('kopi', $result['likes']);
        $this->assertDatabaseHas('ai_logs', ['type' => 'parse_pref', 'status' => 'fallback']);
    }
}
