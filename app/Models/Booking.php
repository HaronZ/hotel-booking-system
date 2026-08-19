<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'room_id',
        'check_in',
        'check_out',
        'guests',
        'status',
        'status_changed_by',
        'total_price',
        'special_requests',
    ];

    /** Statuses that can no longer be changed once reached. */
    public const TERMINAL_STATUSES = ['completed', 'cancelled'];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'total_price' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * Scope bookings that overlap the given date range for a specific room.
     * Used to reject a new/updated booking that would double-book a room.
     */
    public function scopeOverlapping(Builder $query, int $roomId, string $checkIn, string $checkOut): Builder
    {
        return $query->where('room_id', $roomId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('check_in', '<', $checkOut)
            ->whereDate('check_out', '>', $checkIn);
    }
}
