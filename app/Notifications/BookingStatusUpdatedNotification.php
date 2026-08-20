<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingStatusUpdatedNotification extends Notification
{
    use Queueable;

    public function __construct(public Booking $booking)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $room = $this->booking->room;

        return (new MailMessage)
            ->subject('Booking Update - Reservation #'.$this->booking->id)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('There\'s an update on your reservation:')
            ->line('Room: '.$room->room_number.' ('.$room->roomType->name.')')
            ->line('Check-in: '.$this->booking->check_in->toFormattedDateString())
            ->line('Check-out: '.$this->booking->check_out->toFormattedDateString())
            ->line('Status: '.ucfirst($this->booking->status))
            ->line($this->statusLine());
    }

    protected function statusLine(): string
    {
        return match ($this->booking->status) {
            'confirmed' => 'Your booking is confirmed - we look forward to hosting you!',
            'completed' => 'Thanks for staying with us! We hope you enjoyed your visit.',
            'cancelled' => 'This booking has been cancelled.',
            default => 'Your booking status has changed.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'status' => $this->booking->status,
        ];
    }
}
