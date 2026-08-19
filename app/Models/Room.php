<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_type_id',
        'room_number',
        'floor',
        'status',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Scope rooms that have no active booking overlapping the given date range.
     * Two ranges overlap when existing.check_in < new.check_out AND existing.check_out > new.check_in.
     */
    public function scopeAvailableBetween(Builder $query, string $checkIn, string $checkOut): Builder
    {
        return $query->where('status', 'available')
            ->whereDoesntHave('bookings', function (Builder $q) use ($checkIn, $checkOut) {
                $q->whereIn('status', ['pending', 'confirmed'])
                    ->whereDate('check_in', '<', $checkOut)
                    ->whereDate('check_out', '>', $checkIn);
            });
    }
}
