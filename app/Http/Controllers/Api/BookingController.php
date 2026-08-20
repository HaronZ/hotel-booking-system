<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Notifications\BookingStatusUpdatedNotification;
use App\Notifications\NewBookingNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /**
     * List bookings. Customers only see their own; admins see everyone's
     * and can filter by user/room/status/date range.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Booking::query()->with(['room.roomType', 'user:id,name,email', 'statusChangedBy:id,name,role']);

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('room_id')) {
            $query->where('room_id', $request->query('room_id'));
        }

        if ($request->filled('from')) {
            $query->where('check_out', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->where('check_in', '<=', $request->query('to'));
        }

        $bookings = $query->orderByDesc('check_in')->paginate($request->integer('per_page', 15));

        return response()->json($bookings);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $room = Room::with('roomType')->findOrFail($data['room_id']);

        if ($room->status !== 'available') {
            throw ValidationException::withMessages([
                'room_id' => ['This room is not currently available for booking.'],
            ]);
        }

        if ($data['guests'] > $room->roomType->capacity) {
            throw ValidationException::withMessages([
                'guests' => ["This room type only accommodates up to {$room->roomType->capacity} guests."],
            ]);
        }

        $this->assertNoOverlap($room->id, $data['check_in'], $data['check_out']);

        $nights = Carbon::parse($data['check_in'])->diffInDays(Carbon::parse($data['check_out']));

        $booking = Booking::create([
            'user_id' => $request->user()->id,
            'room_id' => $room->id,
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'guests' => $data['guests'],
            'status' => 'pending',
            'status_changed_by' => $request->user()->id,
            'total_price' => $nights * $room->roomType->base_price,
            'special_requests' => $data['special_requests'] ?? null,
        ]);

        $booking->load(['room.roomType', 'user']);

        // The booking itself is already committed - a flaky mail provider
        // (bad key, quota, network) must not turn a successful reservation
        // into a 500 response. Log it and let the guest keep their booking.
        try {
            $booking->user->notify(new NewBookingNotification($booking));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($booking, 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeAccess($request, $booking);

        return response()->json($booking->load(['room.roomType', 'user:id,name,email', 'statusChangedBy:id,name,role']));
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeAccess($request, $booking);
        $this->assertNotTerminal($booking);

        $data = $request->validate([
            'check_in' => ['sometimes', 'date', 'after_or_equal:today'],
            'check_out' => ['sometimes', 'date', 'after:check_in'],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'special_requests' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'in:pending,confirmed,cancelled,completed'],
        ]);

        // Only admins may change status (e.g. confirm/complete a stay).
        if (isset($data['status']) && ! $request->user()->isAdmin()) {
            unset($data['status']);
        }

        $statusChanged = isset($data['status']) && $data['status'] !== $booking->status;
        if ($statusChanged) {
            $data['status_changed_by'] = $request->user()->id;
        }

        $checkIn = $data['check_in'] ?? $booking->check_in->toDateString();
        $checkOut = $data['check_out'] ?? $booking->check_out->toDateString();

        if (isset($data['check_in']) || isset($data['check_out'])) {
            $this->assertNoOverlap($booking->room_id, $checkIn, $checkOut, excludeBookingId: $booking->id);

            $nights = Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));
            $data['total_price'] = $nights * $booking->room->roomType->base_price;
        }

        $booking->update($data);
        $booking = $booking->fresh(['room.roomType', 'user:id,name,email', 'statusChangedBy:id,name,role']);

        // The guest didn't make this change (only admins can), so this is
        // the only way they find out - same failure handling as the
        // booking-created email: never let a mail hiccup fail the request.
        if ($statusChanged) {
            try {
                $booking->user->notify(new BookingStatusUpdatedNotification($booking));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json($booking);
    }

    public function destroy(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeAccess($request, $booking);
        $this->assertNotTerminal($booking);

        $booking->update(['status' => 'cancelled', 'status_changed_by' => $request->user()->id]);

        return response()->json(['message' => 'Booking cancelled.']);
    }

    /**
     * Completed and cancelled are terminal - once a booking reaches either,
     * nothing about it should change. This matters beyond UX: the overlap
     * check skips cancelled bookings, so un-cancelling one after the fact
     * could silently collide with a newer booking that assumed the room
     * was free.
     */
    protected function assertNotTerminal(Booking $booking): void
    {
        abort_if(
            in_array($booking->status, Booking::TERMINAL_STATUSES, true),
            422,
            'This booking is '.$booking->status.' and can no longer be changed.'
        );
    }

    protected function authorizeAccess(Request $request, Booking $booking): void
    {
        abort_unless(
            $request->user()->isAdmin() || $booking->user_id === $request->user()->id,
            403,
            'You do not have access to this booking.'
        );
    }

    /**
     * Reject the request if the room already has a pending/confirmed booking
     * whose date range overlaps [checkIn, checkOut).
     */
    protected function assertNoOverlap(int $roomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): void
    {
        $overlaps = Booking::overlapping($roomId, $checkIn, $checkOut)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'check_in' => ['This room is already booked for part of the selected date range.'],
            ]);
        }
    }
}
