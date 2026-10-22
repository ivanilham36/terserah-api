<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Option;
use Illuminate\Http\Request;

class OptionController extends Controller
{
    public function index(Request $request)
    {
        $query = Option::query()->withAvg('reviews', 'rating')->withCount('reviews');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('q')) {
            $query->where('title', 'like', '%' . $request->q . '%');
        }

        return response()->json($query->latest()->paginate(10));
    }

    public function show(Option $option)
    {
        $option->loadAvg('reviews', 'rating')->loadCount('reviews')->load('reviews.user:id,name');

        return response()->json($option);
    }

    public function store(Request $request)
    {
        $option = Option::create($this->validated($request) + ['created_by' => auth('api')->id()]);

        return response()->json($option, 201);
    }

    public function update(Request $request, Option $option)
    {
        if (! $this->canManage($option)) {
            return response()->json(['message' => 'Tidak diizinkan'], 403);
        }

        $option->update($this->validated($request));

        return response()->json($option);
    }

    public function destroy(Option $option)
    {
        if (! $this->canManage($option)) {
            return response()->json(['message' => 'Tidak diizinkan'], 403);
        }

        $option->delete();

        return response()->json(['message' => 'Opsi dihapus']);
    }

    private function canManage(Option $option): bool
    {
        $user = auth('api')->user();

        return $user->role === 'admin' || $option->created_by === $user->id;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string',
            'category' => 'required|string|max:50',
            'address' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'price_level' => 'required|integer|between:1,4',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:30',
        ]);
    }
}
