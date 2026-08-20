<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\BookingStatusUpdatedNotification;
use Illuminate\Console\Command;

class CompletePastBookings extends Command
{
    protected $signature = 'bookings:complete-past';

    protected $description = 'Mark confirmed bookings as completed once their check-out date has passed';

    public function handle(): int
    {
        $bookings = Booking::where('status', 'confirmed')
            ->whereDate('check_out', '<', now()->toDateString())
            ->get();

        foreach ($bookings as $booking) {
            $booking->update(['status' => 'completed']);

            // Same fire-and-forget handling as the admin status-change path -
            // a mail hiccup here shouldn't stop the rest of the batch or fail the command.
            try {
                $booking->user->notify(new BookingStatusUpdatedNotification($booking));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Marked {$bookings->count()} booking(s) as completed.");

        return self::SUCCESS;
    }
}
