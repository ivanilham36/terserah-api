<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Option;
use App\Models\Room;
use App\Services\PlaceService;
use Illuminate\Http\Request;

class PlaceController extends Controller
{
    // Cari tempat nyata di sekitar titik (lat,lng) atau nama area (area)
    public function search(Request $request, PlaceService $places)
    {
        $data = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'area' => 'nullable|string|max:150',
            'category' => 'required|string|max:50',
            'radius' => 'nullable|integer|between:300,10000',
            'q' => 'nullable|string|max:80',
        ]);

        $center = null;
        if (isset($data['lat'], $data['lng'])) {
            $center = ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'name' => null];
        } elseif (! empty($data['area'])) {
            $center = $places->geocode($data['area']);
            if (! $center) {
                return response()->json(['message' => 'Area tidak ditemukan, coba tulis nama kota atau daerah yang lebih jelas'], 404);
            }
        } else {
            return response()->json(['message' => 'Isi lokasi (lat/lng) atau nama area'], 422);
        }

        $result = $places->search($center['lat'], $center['lng'], $data['category'], (int) ($data['radius'] ?? 2000), $data['q'] ?? null);
        if ($result['error']) {
            return response()->json(['message' => $result['error']], 503);
        }

        return response()->json(['center' => $center, 'places' => $result['places']]);
    }

    // Simpan tempat nyata sebagai opsi (tanpa duplikat) lalu jadikan kandidat ruang
    public function addToRoom(Request $request, Room $room)
    {
        if (! $room->members()->where('users.id', auth('api')->id())->exists()) {
            abort(403, 'Kamu bukan anggota ruang ini');
        }
        if ($room->status !== 'open') {
            abort(422, 'Ruang sudah diputuskan');
        }

        $d = $request->validate([
            'external_id' => ['required', 'string', 'max:40', 'regex:/^(node|way|relation)\/\d+$/'],
            'title' => 'required|string|max:150',
            'category' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'price_level' => 'nullable|integer|between:1,4',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:30',
        ]);

        $option = Option::firstOrCreate(
            ['source' => 'osm', 'external_id' => $d['external_id']],
            [
                'created_by' => auth('api')->id(),
                'title' => $d['title'],
                'category' => $room->category,
                'description' => $d['category'] ?? null,
                'address' => $d['address'] ?? null,
                'lat' => $d['lat'],
                'lng' => $d['lng'],
                'price_level' => $d['price_level'] ?? 2,
                'tags' => $d['tags'] ?? [],
            ]
        );

        $room->options()->syncWithoutDetaching([$option->id]);

        return response()->json($option, 201);
    }
}
