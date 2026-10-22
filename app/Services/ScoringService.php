<?php

namespace App\Services;

use App\Models\Room;

class ScoringService
{
    private const DEFAULT_WEIGHTS = ['preference' => 0.3, 'rating' => 0.25, 'price' => 0.2, 'distance' => 0.25];

    private const STOPWORDS = ['yang', 'dan', 'dengan', 'pengen', 'pingin', 'mau', 'aja', 'asal', 'bebas', 'suka', 'tempat', 'atau', 'yg', 'untuk', 'banget', 'lebih', 'tapi', 'juga', 'kalau', 'karena'];

    public function rank(Room $room): array
    {
        $members = $room->members()->get();
        $options = $room->options()->withAvg('reviews', 'rating')->get();
        $votes = $room->votes()->get()->groupBy('option_id');

        $weights = array_merge(self::DEFAULT_WEIGHTS, $room->weights ?? []);
        if (! $room->use_location) {
            unset($weights['distance']);
        }
        $total = array_sum($weights) ?: 1;
        foreach ($weights as $key => $w) {
            $weights[$key] = $w / $total;
        }

        [$cLat, $cLng] = $this->centroid($members);

        $budgets = [];
        foreach ($members as $m) {
            if ($m->pivot->budget_max) {
                $budgets[] = (int) $m->pivot->budget_max;
            }
        }
        $budgetLevel = $budgets ? $this->budgetToLevel(min($budgets)) : null;

        $prefs = [];
        foreach ($members as $m) {
            $prefs[] = $this->readPref($m->pivot->pref_parsed, $m->pivot->pref_text);
        }

        $ranked = [];
        $excluded = [];

        foreach ($options as $opt) {
            $optVotes = $votes->get($opt->id, collect());

            if ($optVotes->contains('type', 'veto')) {
                $excluded[] = ['option_id' => $opt->id, 'title' => $opt->title, 'reason' => 'Di-veto oleh anggota'];
                continue;
            }

            $text = mb_strtolower($opt->title . ' ' . implode(' ', $opt->tags ?? []));

            if ($this->isBlocked($text, $prefs)) {
                $excluded[] = ['option_id' => $opt->id, 'title' => $opt->title, 'reason' => 'Bertentangan dengan pantangan anggota'];
                continue;
            }

            $parts = [
                'preference' => $this->preferenceScore($text, $prefs),
                'rating' => $opt->reviews_avg_rating ? $opt->reviews_avg_rating / 5 : 0.5,
                'price' => $this->priceScore($opt->price_level, $budgetLevel),
            ];
            if ($room->use_location) {
                $parts['distance'] = $this->distanceScore($opt, $cLat, $cLng);
            }

            $sum = 0;
            foreach ($weights as $key => $w) {
                $sum += $w * $parts[$key];
            }

            $ups = $optVotes->where('type', 'up')->count();
            $final = min(1, $sum + 0.05 * $ups);

            $ranked[] = [
                'option_id' => $opt->id,
                'title' => $opt->title,
                'score' => round($final * 100, 1),
                'breakdown' => array_map(fn ($v) => round($v, 2), $parts),
                'up_votes' => $ups,
            ];
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return ['ranked' => $ranked, 'excluded' => $excluded, 'weights' => $weights];
    }

    private function readPref($parsed, ?string $text): array
    {
        if (is_string($parsed)) {
            $parsed = json_decode($parsed, true);
        }

        return [
            'likes' => $parsed['likes'] ?? $this->tokens($text),
            'avoid' => $parsed['avoid'] ?? [],
        ];
    }

    private function tokens(?string $text): array
    {
        if (! $text) {
            return [];
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true)));
    }

    private function isBlocked(string $text, array $prefs): bool
    {
        foreach ($prefs as $p) {
            foreach ($p['avoid'] as $term) {
                $term = mb_strtolower(trim($term));
                if ($term !== '' && str_contains($text, $term)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function preferenceScore(string $text, array $prefs): float
    {
        $withLikes = 0;
        $matched = 0;
        foreach ($prefs as $p) {
            if (! $p['likes']) {
                continue;
            }
            $withLikes++;
            foreach ($p['likes'] as $term) {
                if ($term !== '' && str_contains($text, mb_strtolower($term))) {
                    $matched++;
                    break;
                }
            }
        }

        return $withLikes ? $matched / $withLikes : 0.5;
    }

    private function budgetToLevel(int $budget): int
    {
        return match (true) {
            $budget <= 30000 => 1,
            $budget <= 60000 => 2,
            $budget <= 100000 => 3,
            default => 4,
        };
    }

    private function priceScore(?int $level, ?int $budgetLevel): float
    {
        if (! $level || ! $budgetLevel) {
            return 0.5;
        }
        $diff = $level - $budgetLevel;

        return $diff <= 0 ? 1.0 : max(0, 1 - 0.5 * $diff);
    }

    private function centroid($members): array
    {
        $lat = [];
        $lng = [];
        foreach ($members as $m) {
            if ($m->pivot->lat !== null && $m->pivot->lng !== null) {
                $lat[] = (float) $m->pivot->lat;
                $lng[] = (float) $m->pivot->lng;
            }
        }

        return $lat ? [array_sum($lat) / count($lat), array_sum($lng) / count($lng)] : [null, null];
    }

    private function distanceScore($opt, ?float $cLat, ?float $cLng): float
    {
        if ($cLat === null || $opt->lat === null || $opt->lng === null) {
            return 0.5;
        }

        return max(0, 1 - $this->haversine($cLat, $cLng, (float) $opt->lat, (float) $opt->lng) / 10);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }
}

