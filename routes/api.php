<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomTypeController;
use Illuminate\Support\Facades\Route;

// --- Public ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/chatbot', [ChatbotController::class, 'ask']);

Route::apiResource('room-types', RoomTypeController::class)->only(['index', 'show']);
Route::apiResource('rooms', RoomController::class)->only(['index', 'show']);

// --- Authenticated (any logged-in user) ---
Route::middleware('auth:api')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('bookings', BookingController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    // --- Authenticated + admin only ---
    Route::middleware('admin')->group(function () {
        Route::apiResource('room-types', RoomTypeController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('rooms', RoomController::class)->only(['store', 'update', 'destroy']);
    });
});
