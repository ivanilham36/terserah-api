<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mencari tempat nyata dari OpenStreetMap (Overpass API + Nominatim).
 * Gratis tanpa API key. OSM tidak punya rating, jadi rating berasal dari ulasan pengguna aplikasi.
 */
class PlaceService
{
    private const OVERPASS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    private const NOMINATIM = 'https://nominatim.openstreetmap.org/search';

    // kata kunci kategori ruang -> filter OSM
    private const CATEGORY_FILTERS = [
        'kopi' => [['amenity', 'cafe']],
        'kafe' => [['amenity', 'cafe']],
        'cafe' => [['amenity', 'cafe']],
        'makan' => [['amenity', 'restaurant'], ['amenity', 'cafe'], ['amenity', 'fast_food'], ['amenity', 'food_court']],
        'resto' => [['amenity', 'restaurant'], ['amenity', 'fast_food']],
        'film' => [['amenity', 'cinema']],
        'bioskop' => [['amenity', 'cinema']],
        'nonton' => [['amenity', 'cinema']],
        'wisata' => [['tourism', 'attraction'], ['tourism', 'museum'], ['tourism', 'viewpoint'], ['tourism', 'zoo'], ['tourism', 'theme_park'], ['leisure', 'park']],
        'liburan' => [['tourism', 'attraction'], ['tourism', 'museum'], ['tourism', 'viewpoint'], ['tourism', 'zoo'], ['tourism', 'theme_park'], ['leisure', 'park']],
        'jalan' => [['tourism', 'attraction'], ['leisure', 'park'], ['shop', 'mall']],
        'hotel' => [['tourism', 'hotel'], ['tourism', 'guest_house']],
        'nginap' => [['tourism', 'hotel'], ['tourism', 'guest_house']],
        'hadiah' => [['shop', 'gift'], ['shop', 'mall'], ['shop', 'books']],
        'belanja' => [['shop', 'mall'], ['shop', 'supermarket'], ['shop', 'department_store']],
        'olahraga' => [['leisure', 'fitness_centre'], ['leisure', 'sports_centre']],
        'taman' => [['leisure', 'park']],
    ];

    // kata kunci kategori ruang -> kategori Geoapify
    private const GEO_CATEGORIES = [
        'kopi' => ['catering.cafe'],
        'kafe' => ['catering.cafe'],
        'cafe' => ['catering.cafe'],
        'makan' => ['catering.restaurant', 'catering.fast_food', 'catering.cafe', 'catering.food_court'],
        'resto' => ['catering.restaurant', 'catering.fast_food'],
        'film' => ['entertainment.cinema'],
        'bioskop' => ['entertainment.cinema'],
        'nonton' => ['entertainment.cinema'],
        'wisata' => ['tourism.attraction', 'tourism.sights', 'entertainment.museum', 'entertainment.zoo', 'entertainment.theme_park', 'leisure.park'],
        'liburan' => ['tourism.attraction', 'tourism.sights', 'entertainment.museum', 'entertainment.zoo', 'entertainment.theme_park', 'leisure.park'],
        'jalan' => ['tourism.attraction', 'leisure.park', 'commercial.shopping_mall'],
        'hotel' => ['accommodation.hotel', 'accommodation.guest_house'],
        'nginap' => ['accommodation.hotel', 'accommodation.guest_house'],
        'hadiah' => ['commercial.gift_and_souvenir', 'commercial.shopping_mall', 'commercial.books'],
        'belanja' => ['commercial.shopping_mall', 'commercial.supermarket', 'commercial.department_store'],
        'olahraga' => ['sport.fitness', 'sport.sports_centre'],
        'taman' => ['leisure.park'],
    ];

    private const GEO_DEFAULT = ['catering.restaurant', 'catering.fast_food', 'catering.cafe'];

    private const GEOAPIFY = 'https://api.geoapify.com';

    private const DEFAULT_FILTERS = [['amenity', 'restaurant'], ['amenity', 'cafe'], ['amenity', 'fast_food']];

    public function filtersFor(string $category): array
    {
        $category = mb_strtolower($category);
        foreach (self::CATEGORY_FILTERS as $key => $filters) {
            if (str_contains($category, $key)) {
                return $filters;
            }
        }

        return self::DEFAULT_FILTERS;
    }

    public function geoCategoriesFor(string $category): array
    {
        $category = mb_strtolower($category);
        foreach (self::GEO_CATEGORIES as $key => $cats) {
            if (str_contains($category, $key)) {
                return $cats;
            }
        }

        return self::GEO_DEFAULT;
    }

    private function geoKey(): ?string
    {
        return config('services.geoapify.key') ?: null;
    }

    public function buildQuery(array $filters, float $lat, float $lng, int $radius, int $limit): string
    {
        $parts = '';
        foreach ($filters as [$k, $v]) {
            $parts .= sprintf('nwr["%s"="%s"]["name"](around:%d,%F,%F);', $k, $v, $radius, $lat, $lng);
        }

        return sprintf('[out:json][timeout:9];(%s);out center tags %d;', $parts, $limit);
    }

    /** @return array{places: array, error: ?string} */
    public function search(float $lat, float $lng, string $category, int $radius = 2000, ?string $q = null, int $limit = 30): array
    {
        $radius = max(300, min($radius, 10000));
        $key = 'places:' . md5(json_encode([round($lat, 3), round($lng, 3), $category, $radius, $q, $limit]));

        if ($cached = Cache::get($key)) {
            return ['places' => $cached, 'error' => null];
        }

        $places = null;
        if ($this->geoKey()) {
            $places = $this->geoapifySearch($lat, $lng, $category, $radius);
        }
        if ($places === null) {
            $data = $this->overpass($this->buildQuery($this->filtersFor($category), $lat, $lng, $radius, 80));
            if ($data === null) {
                return ['places' => [], 'error' => 'Layanan peta sedang sibuk, coba lagi sebentar lagi'];
            }
            $places = $this->normalize($data['elements'] ?? [], $lat, $lng);
        }

        if ($q) {
            $needle = mb_strtolower($q);
            $places = array_values(array_filter($places, fn ($p) => str_contains(mb_strtolower($p['title'] . ' ' . implode(' ', $p['tags'])), $needle)));
        }

        $places = array_slice($places, 0, $limit);
        if ($places) {
            Cache::put($key, $places, now()->addMinutes(15));
        }

        return ['places' => $places, 'error' => null];
    }

    /** Ubah nama area ("Kemang, Jakarta") menjadi koordinat. */
    public function geocode(string $text): ?array
    {
        $key = 'geo:' . md5(mb_strtolower($text));

        return Cache::remember($key, now()->addDay(), function () use ($text) {
            if ($this->geoKey()) {
                try {
                    $res = Http::connectTimeout(4)->timeout(8)->get(self::GEOAPIFY . '/v1/geocode/search', [
                        'text' => $text, 'format' => 'json', 'limit' => 1, 'lang' => 'id', 'apiKey' => $this->geoKey(),
                    ]);
                    $hit = $res->successful() ? ($res->json('results.0') ?? null) : null;
                    if ($hit) {
                        return ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lon'], 'name' => $hit['formatted'] ?? $text];
                    }
                } catch (\Throwable $e) {
                    Log::warning('Geoapify geocode gagal', ['error' => $e->getMessage()]);
                }
            }

            try {
                $res = Http::connectTimeout(4)->timeout(8)->withHeaders($this->headers())->get(self::NOMINATIM, [
                    'q' => $text, 'format' => 'json', 'limit' => 1, 'accept-language' => 'id',
                ]);
            } catch (\Throwable) {
                return null;
            }
            $hit = $res->successful() ? ($res->json()[0] ?? null) : null;

            return $hit ? ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lon'], 'name' => $hit['display_name'] ?? $text] : null;
        });
    }

    public function normalize(array $elements, float $lat, float $lng): array
    {
        $out = [];
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $pLat = $el['lat'] ?? ($el['center']['lat'] ?? null);
            $pLng = $el['lon'] ?? ($el['center']['lon'] ?? null);
            if (empty($tags['name']) || $pLat === null || $pLng === null) {
                continue;
            }

            $out[] = [
                'external_id' => $el['type'] . '/' . $el['id'],
                'title' => $tags['name'],
                'category' => $this->kind($tags),
                'address' => $this->address($tags),
                'lat' => (float) $pLat,
                'lng' => (float) $pLng,
                'price_level' => $this->priceLevel($tags),
                'tags' => $this->tags($tags),
                'opening_hours' => $tags['opening_hours'] ?? null,
                'website' => $tags['website'] ?? ($tags['contact:website'] ?? null),
                'distance_km' => round($this->haversine($lat, $lng, (float) $pLat, (float) $pLng), 2),
            ];
        }

        usort($out, fn ($a, $b) => $a['distance_km'] <=> $b['distance_km']);

        return $out;
    }

    /** Cari lewat Geoapify (data OSM, ramah hosting cloud). Null bila gagal. */
    private function geoapifySearch(float $lat, float $lng, string $category, int $radius): ?array
    {
        try {
            $res = Http::connectTimeout(4)->timeout(10)->get(self::GEOAPIFY . '/v2/places', [
                'categories' => implode(',', $this->geoCategoriesFor($category)),
                'filter' => sprintf('circle:%F,%F,%d', $lng, $lat, $radius),
                'bias' => sprintf('proximity:%F,%F', $lng, $lat),
                'limit' => 60,
                'lang' => 'id',
                'apiKey' => $this->geoKey(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Geoapify gagal', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful() || ! is_array($res->json('features'))) {
            Log::warning('Geoapify respons tidak valid', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 200)]);

            return null;
        }

        $out = [];
        foreach ($res->json('features') as $f) {
            $p = $f['properties'] ?? [];
            $raw = $p['datasource']['raw'] ?? [];
            if (empty($p['name']) || ! isset($p['lat'], $p['lon'])) {
                continue;
            }

            $type = ['n' => 'node', 'w' => 'way', 'r' => 'relation'][$raw['osm_type'] ?? ''] ?? null;
            $externalId = ($type && isset($raw['osm_id']))
                ? $type . '/' . $raw['osm_id']
                : 'geo/' . substr(md5($p['place_id'] ?? $p['name']), 0, 20);

            $out[] = [
                'external_id' => $externalId,
                'title' => $p['name'],
                'category' => $this->geoKind($p['categories'] ?? []),
                'address' => $p['address_line2'] ?? ($p['formatted'] ?? null),
                'lat' => (float) $p['lat'],
                'lng' => (float) $p['lon'],
                'price_level' => $this->priceLevel($raw),
                'tags' => $this->tags($raw),
                'opening_hours' => $raw['opening_hours'] ?? null,
                'website' => $raw['website'] ?? ($raw['contact:website'] ?? null),
                'distance_km' => round($this->haversine($lat, $lng, (float) $p['lat'], (float) $p['lon']), 2),
            ];
        }

        usort($out, fn ($a, $b) => $a['distance_km'] <=> $b['distance_km']);

        return $out;
    }

    private function geoKind(array $categories): string
    {
        foreach ($categories as $c) {
            if (str_contains($c, '.')) {
                return str_replace(['.', '_'], [' / ', ' '], $c);
            }
        }

        return 'tempat';
    }

    private function kind(array $t): string
    {
        return $t['amenity'] ?? $t['tourism'] ?? $t['leisure'] ?? $t['shop'] ?? 'tempat';
    }

    private function address(array $t): ?string
    {
        $street = trim(($t['addr:street'] ?? '') . ' ' . ($t['addr:housenumber'] ?? ''));
        $parts = array_filter([$street, $t['addr:suburb'] ?? null, $t['addr:city'] ?? null]);

        return $parts ? implode(', ', $parts) : ($t['addr:full'] ?? null);
    }

    // OSM tidak punya harga: tebak kasar dari jenis tempat
    private function priceLevel(array $t): int
    {
        if (! empty($t['fee']) && $t['fee'] === 'no') {
            return 1;
        }

        return match ($t['amenity'] ?? $t['tourism'] ?? $t['shop'] ?? '') {
            'fast_food', 'food_court', 'park' => 1,
            'cafe', 'cinema', 'attraction', 'museum', 'viewpoint', 'guest_house', 'gift', 'books' => 2,
            'restaurant', 'zoo', 'mall', 'hotel', 'theme_park' => 3,
            default => 2,
        };
    }

    private function tags(array $t): array
    {
        $tags = [];
        foreach (['cuisine', 'diet:halal', 'internet_access', 'outdoor_seating', 'air_conditioning', 'takeaway', 'wheelchair'] as $k) {
            if (! isset($t[$k])) {
                continue;
            }
            $v = $t[$k];
            match ($k) {
                'cuisine' => $tags = array_merge($tags, array_slice(array_map('trim', explode(';', str_replace('_', ' ', $v))), 0, 3)),
                'diet:halal' => $v === 'yes' ? $tags[] = 'halal' : null,
                'internet_access' => in_array($v, ['wlan', 'yes', 'wifi'], true) ? $tags[] = 'wifi' : null,
                'outdoor_seating' => $v === 'yes' ? $tags[] = 'outdoor' : null,
                'air_conditioning' => $v === 'yes' ? $tags[] = 'ber-ac' : null,
                'takeaway' => $v === 'yes' ? $tags[] = 'takeaway' : null,
                'wheelchair' => $v === 'yes' ? $tags[] = 'ramah-kursi-roda' : null,
            };
        }

        return array_values(array_unique(array_map(fn ($x) => mb_substr($x, 0, 30), $tags)));
    }

    private function overpass(string $query): ?array
    {
        foreach (self::OVERPASS as $url) {
            try {
                $res = Http::connectTimeout(4)->timeout(9)->withHeaders($this->headers())->asForm()->post($url, ['data' => $query]);
            } catch (\Throwable $e) {
                Log::warning('Overpass gagal', ['url' => $url, 'error' => $e->getMessage()]);

                continue;
            }
            if ($res->successful() && is_array($res->json()) && isset($res->json()['elements'])) {
                return $res->json();
            }
            Log::warning('Overpass respons tidak valid', ['url' => $url, 'status' => $res->status()]);
        }

        return null;
    }

    private function headers(): array
    {
        return ['User-Agent' => 'TerserahApp/1.0 (tugas kuliah Universitas ASA Indonesia)', 'Referer' => config('app.url')];
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371 * asin(sqrt($a));
    }
}
