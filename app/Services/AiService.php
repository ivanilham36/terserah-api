<?php

namespace App\Services;

use App\Models\AiLog;
use App\Models\Room;
use Illuminate\Support\Facades\Http;

class AiService
{
    private const STOPWORDS = ['yang', 'dan', 'dengan', 'pengen', 'pingin', 'mau', 'aja', 'asal', 'bebas', 'suka', 'tempat', 'atau', 'yg', 'untuk', 'banget', 'lebih', 'tidak', 'nggak', 'gak', 'bukan', 'anti', 'jangan', 'tanpa', 'alergi', 'makan', 'tapi', 'juga', 'kalau', 'karena'];

    private const LABELS = [
        'preference' => 'kecocokan dengan preferensi anggota',
        'rating' => 'rating ulasan',
        'price' => 'kesesuaian dengan budget',
        'distance' => 'jarak yang dekat',
    ];

    // 1. Preferensi bebas -> data terstruktur {likes, avoid}
    public function parsePreference(string $text, ?int $roomId = null): array
    {
        $system = 'Kamu pengurai preferensi. Dari kalimat pengguna, ambil kata kunci pendek (huruf kecil, bahasa Indonesia). '
            . '"likes" = hal yang diinginkan, "avoid" = hal yang tidak diinginkan atau pantangan. Maksimal 8 kata per daftar. '
            . 'Balas HANYA JSON valid tanpa teks lain, contoh: {"likes":["halal","murah"],"avoid":["pedas"]}';

        [$reply, $ms, $err] = $this->call($system, $text);
        $clean = $reply ? $this->cleanPref($this->extractJson($reply)) : null;

        if ($clean) {
            $this->log($roomId, 'parse_pref', $text, $reply, 'ok', $ms);

            return $clean;
        }

        $fallback = $this->fallbackPref($text);
        $this->log($roomId, 'parse_pref', $text, json_encode($fallback) . ' | ' . ($err ?? 'respons AI tidak valid'), 'fallback', $ms);

        return $fallback;
    }

    // 2. Penjelasan keputusan (AI, dengan fallback templat)
    public function explainDecision(Room $room, int $optionId, array $ranking): string
    {
        $entry = collect($ranking['ranked'])->firstWhere('option_id', $optionId);
        $title = $entry['title'] ?? 'opsi terpilih';
        $score = $entry['score'] ?? null;

        $prefs = $room->members()->get()->map(fn ($m) => $m->name . ': ' . ($m->pivot->pref_text ?: '-'))->implode('; ');
        $opt = $room->options()->where('options.id', $optionId)->first();
        $optTags = $opt ? ($opt->tags ?? []) : [];
        $facts = 'Ruang: ' . $room->name . ' (kategori ' . $room->category . '). Terpilih: ' . $title
            . ($score !== null ? ', skor ' . $score . '/100' : '')
            . '. Rincian skor (0-1): ' . json_encode($entry['breakdown'] ?? [])
            . '. Tag opsi: ' . ($optTags ? implode(', ', $optTags) : '-') . '. Preferensi anggota: ' . $prefs . '.';

        $system = 'Kamu menjelaskan hasil keputusan kelompok dengan ramah dalam bahasa Indonesia, 2-3 kalimat. '
            . 'Gunakan HANYA fakta yang diberikan. Jangan menyebut sifat opsi (misalnya halal, nyaman, enak) kecuali ada di tag opsi, dan jangan mengarang data baru.';

        [$reply, $ms, $err] = $this->call($system, $facts);

        if ($reply) {
            $this->log($room->id, 'explain', $facts, $reply, 'ok', $ms);

            return trim($reply);
        }

        $text = $this->templateExplanation($title, $score, $entry['breakdown'] ?? []);
        $this->log($room->id, 'explain', $facts, $text . ' | ' . $err, 'fallback', $ms);

        return $text;
    }

    // 3. Saran opsi (ide umum, bukan tempat nyata)
    public function suggestOptions(Room $room, string $topic, int $count = 5): array
    {
        $system = 'Kamu pemberi ide untuk keputusan kelompok. Beri ide opsi yang UMUM: judul berupa jenis hidangan atau ide generik (contoh: Soto ayam), BUKAN nama warung, kafe, atau toko. Tag berisi ciri opsi itu sendiri, jangan menyalin kalimat preferensi. DILARANG menyebut nama tempat, bisnis, atau merek nyata, '
            . 'dan DILARANG mengarang alamat. Balas HANYA JSON array berisi objek: '
            . '[{"title":"...","description":"...","tags":["..."],"price_level":1}] dengan price_level 1 sampai 4.';
        $avoid = $room->members()->get()->flatMap(function ($m) {
            $p = $m->pivot->pref_parsed;
            $p = is_string($p) ? json_decode($p, true) : $p;

            return $p['avoid'] ?? [];
        })->unique()->implode(', ');
        $user = 'Kategori: ' . $room->category . '. Topik: ' . ($topic ?: $room->name) . '. Jumlah ide: ' . $count
            . '. Pantangan anggota (jangan disarankan): ' . ($avoid ?: '-')
            . '. Tag harus ciri opsi itu sendiri (misalnya jenis masakan atau rasa), bukan preferensi anggota. Tulis tag dalam bahasa Indonesia.';

        [$reply, $ms, $err] = $this->call($system, $user);
        $items = $reply ? $this->extractJson($reply, true) : null;

        $clean = [];
        foreach ((array) $items as $it) {
            if (is_array($it) && ! empty($it['title'])) {
                $clean[] = [
                    'title' => mb_substr((string) $it['title'], 0, 150),
                    'description' => mb_substr((string) ($it['description'] ?? ''), 0, 300),
                    'tags' => array_slice(array_map('strval', (array) ($it['tags'] ?? [])), 0, 6),
                    'price_level' => max(1, min(4, (int) ($it['price_level'] ?? 1))),
                ];
            }
        }
        $clean = array_slice($clean, 0, $count);

        $this->log($room->id, 'suggest', $user, $reply ?? ($err ?? ''), $clean ? 'ok' : 'error', $ms);

        return $clean;
    }

    // ---- internal ----

    private function call(string $system, string $user): array
    {
        $key = config('services.ai.key');
        if (! $key) {
            return [null, 0, 'API key belum diisi'];
        }

        // Daftar model dicoba berurutan; lanjut ke model berikutnya jika limit habis / error
        $models = array_values(array_filter(array_map('trim', explode(',', (string) config('services.ai.models')))));
        $start = microtime(true);
        $err = 'tidak ada model dikonfigurasi';

        foreach ($models as $model) {
            try {
                $res = Http::withToken($key)->acceptJson()->timeout(12)->post(rtrim(config('services.ai.base_url'), '/') . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
            } catch (\Throwable $e) {
                $err = $model . ': ' . mb_substr($e->getMessage(), 0, 150);
                continue;
            }

            if ($res->successful()) {
                $text = trim((string) preg_replace('/<think>.*?<\/think>/s', '', (string) $res->json('choices.0.message.content')));
                if ($text !== '') {
                    return [$text, (int) ((microtime(true) - $start) * 1000), null];
                }
                $err = $model . ': respons kosong';
                continue;
            }

            $err = $model . ': HTTP ' . $res->status();
            if (in_array($res->status(), [401, 403], true)) {
                break; // masalah key, model lain tidak akan membantu
            }
        }

        return [null, (int) ((microtime(true) - $start) * 1000), $err];
    }

    private function extractJson(string $text, bool $list = false): ?array
    {
        $pattern = $list ? '/\[.*\]/s' : '/\{.*\}/s';
        if (! preg_match($pattern, $text, $m)) {
            return null;
        }
        $data = json_decode($m[0], true);

        return is_array($data) ? $data : null;
    }

    private function cleanPref(?array $data): ?array
    {
        if (! $data || (! isset($data['likes']) && ! isset($data['avoid']))) {
            return null;
        }
        $norm = function ($list) {
            $out = [];
            foreach ((array) $list as $w) {
                $w = mb_strtolower(trim((string) $w));
                if ($w !== '' && mb_strlen($w) <= 30) {
                    $out[] = $w;
                }
            }

            return array_slice(array_values(array_unique($out)), 0, 8);
        };

        return ['likes' => $norm($data['likes'] ?? []), 'avoid' => $norm($data['avoid'] ?? [])];
    }

    private function fallbackPref(string $text): array
    {
        $lower = mb_strtolower($text);
        $avoid = [];
        if (preg_match_all('/\b(?:tidak|nggak|gak|ga|bukan|anti|jangan|tanpa|alergi)\s+(?:mau\s+|suka\s+|makan\s+)?([\p{L}\p{N}]+)/u', $lower, $m)) {
            $avoid = $m[1];
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $likes = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 3
            && ! in_array($w, self::STOPWORDS, true) && ! in_array($w, $avoid, true)));

        return $this->cleanPref(['likes' => $likes, 'avoid' => $avoid]);
    }

    private function templateExplanation(string $title, $score, array $breakdown): string
    {
        $text = '"' . $title . '" terpilih' . ($score !== null ? ' dengan skor ' . $score . ' dari 100' : '') . '.';
        if ($breakdown) {
            arsort($breakdown);
            $best = array_key_first($breakdown);
            $text .= ' Keunggulan terbesarnya ada pada ' . (self::LABELS[$best] ?? $best) . '.';
        }

        return $text;
    }

    private function log(?int $roomId, string $type, string $prompt, ?string $response, string $status, int $ms): void
    {
        AiLog::create([
            'room_id' => $roomId, 'type' => $type, 'prompt' => $prompt,
            'response' => $response, 'status' => $status, 'latency_ms' => $ms,
        ]);
    }
}





