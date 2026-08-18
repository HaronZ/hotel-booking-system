<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewBookingNotification extends Notification
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
            ->subject('Booking Received - Reservation #'.$this->booking->id)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Thanks for your reservation! Here are the details:')
            ->line('Room: '.$room->room_number.' ('.$room->roomType->name.')')
            ->line('Check-in: '.$this->booking->check_in->toFormattedDateString())
            ->line('Check-out: '.$this->booking->check_out->toFormattedDateString())
            ->line('Guests: '.$this->booking->guests)
            ->line('Total price: ₱'.number_format((float) $this->booking->total_price, 2))
            ->line('Status: '.ucfirst($this->booking->status))
            ->line('We will confirm your booking shortly. Thank you for choosing us!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'room_number' => $this->booking->room->room_number,
            'check_in' => $this->booking->check_in->toDateString(),
            'check_out' => $this->booking->check_out->toDateString(),
        ];
    }
}
