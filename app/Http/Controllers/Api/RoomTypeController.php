<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomTypeRequest;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    /**
     * Display a listing of the resource, with optional search and price filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RoomType::query()->withCount('rooms');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('min_price')) {
            $query->where('base_price', '>=', $request->query('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('base_price', '<=', $request->query('max_price'));
        }

        if ($request->filled('capacity')) {
            $query->where('capacity', '>=', $request->query('capacity'));
        }

        $roomTypes = $query->orderBy('base_price')->paginate($request->integer('per_page', 15));

        return response()->json($roomTypes);
    }

    public function store(StoreRoomTypeRequest $request): JsonResponse
    {
        $roomType = RoomType::create($request->validated());

        return response()->json($roomType, 201);
    }

    public function show(RoomType $roomType): JsonResponse
    {
        return response()->json($roomType->loadCount('rooms'));
    }

    public function update(StoreRoomTypeRequest $request, RoomType $roomType): JsonResponse
    {
        $roomType->update($request->validated());

        return response()->json($roomType);
    }

    public function destroy(RoomType $roomType): JsonResponse
    {
        $roomType->delete();

        return response()->json(['message' => 'Room type deleted.']);
    }
}
