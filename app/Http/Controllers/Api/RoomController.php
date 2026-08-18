<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    /**
     * Display a listing of rooms with search/filtering, including live
     * availability search when both check_in and check_out are given.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'check_in' => ['nullable', 'date'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Room::query()->with('roomType');

        if ($request->filled('room_type_id')) {
            $query->where('room_type_id', $request->query('room_type_id'));
        }

        if ($request->filled('guests')) {
            $query->whereHas('roomType', function ($q) use ($request) {
                $q->where('capacity', '>=', $request->query('guests'));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($search = $request->query('search')) {
            $query->where('room_number', 'like', "%{$search}%");
        }

        if ($request->filled('check_in') && $request->filled('check_out')) {
            $query->availableBetween($request->query('check_in'), $request->query('check_out'));
        }

        $rooms = $query->orderBy('room_number')->paginate($request->integer('per_page', 15));

        return response()->json($rooms);
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $room = Room::create($request->validated());

        return response()->json($room->load('roomType'), 201);
    }

    public function show(Room $room): JsonResponse
    {
        return response()->json($room->load('roomType'));
    }

    public function update(StoreRoomRequest $request, Room $room): JsonResponse
    {
        $room->update($request->validated());

        return response()->json($room->load('roomType'));
    }

    public function destroy(Room $room): JsonResponse
    {
        $room->delete();

        return response()->json(['message' => 'Room deleted.']);
    }
}
