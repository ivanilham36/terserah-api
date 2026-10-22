<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Option;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Option $option)
    {
        $data = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'comment' => 'nullable|string|max:500',
        ]);

        $review = Review::updateOrCreate(
            ['option_id' => $option->id, 'user_id' => auth('api')->id()],
            $data
        );

        return response()->json($review, 201);
    }

    public function destroy(Review $review)
    {
        $user = auth('api')->user();
        if ($user->role !== 'admin' && $review->user_id !== $user->id) {
            return response()->json(['message' => 'Tidak diizinkan'], 403);
        }

        $review->delete();

        return response()->json(['message' => 'Ulasan dihapus']);
    }
}
