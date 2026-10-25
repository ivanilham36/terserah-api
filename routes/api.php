<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('google', [AuthController::class, 'google'])->middleware('throttle:20,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware('auth:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

Route::middleware('auth:api')->group(function () {
    Route::apiResource('options', \App\Http\Controllers\Api\OptionController::class);
    Route::post('options/{option}/reviews', [\App\Http\Controllers\Api\ReviewController::class, 'store']);
    Route::delete('reviews/{review}', [\App\Http\Controllers\Api\ReviewController::class, 'destroy']);
});

Route::middleware('auth:api')->group(function () {
    $c = \App\Http\Controllers\Api\RoomController::class;
    Route::get('rooms', [$c, 'index']);
    Route::post('rooms', [$c, 'store']);
    Route::post('rooms/join', [$c, 'join']);
    Route::get('rooms/{room}', [$c, 'show']);
    Route::put('rooms/{room}/me', [$c, 'updateMe']);
    Route::post('rooms/{room}/options', [$c, 'addOption']);
    Route::delete('rooms/{room}/options/{optionId}', [$c, 'removeOption']);
    Route::post('rooms/{room}/votes', [$c, 'vote']);
    Route::get('rooms/{room}/recommendations', [$c, 'recommendations']);
    Route::post('rooms/{room}/decide', [$c, 'decide']);
    Route::delete('rooms/{room}', [$c, 'destroy']);
    Route::delete('rooms/{room}/members/me', [$c, 'leave']);
    Route::post('rooms/{room}/places', [\App\Http\Controllers\Api\PlaceController::class, 'addToRoom']);
});

Route::middleware('auth:api')->post('rooms/{room}/ai/suggest', [\App\Http\Controllers\Api\RoomController::class, 'suggest']);

Route::middleware('auth:api')->delete('rooms/{room}/votes/{optionId}', [\App\Http\Controllers\Api\RoomController::class, 'unvote']);

Route::middleware('auth:api')->get('places/search', [\App\Http\Controllers\Api\PlaceController::class, 'search']);
