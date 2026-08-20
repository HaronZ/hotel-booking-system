<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A hybrid chatbot: deterministic intent-matching first, LLM fallback second.
 *
 * The common, well-defined questions (prices, availability, booking/cancel
 * help) are answered by keyword-matched rules that query the database
 * directly - free, instant, and fully traceable to a single line of code.
 * Anything that doesn't match falls through to an LLM call (via OpenRouter),
 * grounded with the real room data so it can't invent rooms or prices that
 * don't exist. If that call fails for any reason, it degrades to a plain
 * fallback message rather than erroring out.
 */
class ChatbotService
{
    public function reply(string $message): array
    {
        $text = Str::lower(trim($message));

        return match (true) {
            $text === '' => $this->fallback(),
            $this->matches($text, ['hi', 'hello', 'hey', 'good morning', 'good afternoon']) => $this->greeting(),
            $this->matches($text, ['available', 'availability', 'vacant', 'free room']) => $this->availability($text),
            $this->matches($text, ['price', 'rate', 'cost', 'how much']) => $this->roomTypes(),
            $this->matches($text, ['room type', 'rooms do you have', 'types of room']) => $this->roomTypes(),
            $this->matches($text, ['amenities', 'wifi', 'breakfast', 'facilit']) => $this->amenities(),
            $this->matches($text, ['cancel']) => $this->cancelHelp(),
            $this->matches($text, ['book', 'reservation', 'reserve']) => $this->bookingHelp(),
            $this->containsDateRange($text) => $this->availability($text),
            default => $this->askLlm($message),
        };
    }

    protected function matches(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (Str::contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the message contains two ISO dates on its own, e.g. a
     * reply of just "2026-09-01 to 2026-09-05" to the availability prompt.
     * The chatbot has no conversation memory, so this is what lets that
     * kind of follow-up still resolve to a real DB availability lookup
     * instead of falling through to the LLM.
     */
    protected function containsDateRange(string $text): bool
    {
        preg_match_all('/\d{4}-\d{2}-\d{2}/', $text, $matches);

        return count($matches[0] ?? []) >= 2;
    }

    protected function greeting(): array
    {
        return $this->respond(
            "Hello! I'm the booking assistant. I can tell you about room types and prices, ".
            "check room availability for specific dates, and walk you through how to book or cancel a reservation. What would you like to know?"
        );
    }

    protected function roomTypes(): array
    {
        $roomTypes = RoomType::query()->orderBy('base_price')->get(['name', 'base_price', 'capacity']);

        if ($roomTypes->isEmpty()) {
            return $this->respond('We do not have any room types configured yet.');
        }

        $lines = $roomTypes->map(
            fn (RoomType $type) => "- {$type->name}: ₱".number_format((float) $type->base_price, 2)."/night, up to {$type->capacity} guest(s)"
        )->implode("\n");

        return $this->respond("Here are our room types:\n{$lines}");
    }

    protected function amenities(): array
    {
        $amenities = RoomType::query()->pluck('amenities')
            ->flatten(1)
            ->filter()
            ->unique()
            ->values();

        if ($amenities->isEmpty()) {
            return $this->respond('Amenity details have not been added for our room types yet.');
        }

        return $this->respond('Amenities across our rooms include: '.$amenities->implode(', ').'.');
    }

    /**
     * Look for two ISO dates (YYYY-MM-DD) in the message and, if found,
     * query real room availability. Otherwise ask the user for dates.
     */
    protected function availability(string $text): array
    {
        preg_match_all('/\d{4}-\d{2}-\d{2}/', $text, $matches);
        $dates = $matches[0] ?? [];

        if (count($dates) < 2) {
            return $this->respond(
                'Sure — what check-in and check-out dates? Please give me two dates in YYYY-MM-DD format, '.
                'for example: "available 2026-09-01 to 2026-09-05".'
            );
        }

        [$checkIn, $checkOut] = [min($dates), max($dates)];

        if ($checkIn === $checkOut) {
            return $this->respond('Check-in and check-out dates cannot be the same day.');
        }

        $rooms = Room::query()
            ->with('roomType')
            ->availableBetween($checkIn, $checkOut)
            ->get();

        if ($rooms->isEmpty()) {
            return $this->respond("Sorry, no rooms are available from {$checkIn} to {$checkOut}. Try different dates?");
        }

        $lines = $rooms->map(
            fn (Room $room) => "- Room {$room->room_number} ({$room->roomType->name}, ₱".number_format((float) $room->roomType->base_price, 2)."/night)"
        )->implode("\n");

        return $this->respond("Rooms available from {$checkIn} to {$checkOut}:\n{$lines}");
    }

    protected function bookingHelp(): array
    {
        return $this->respond(
            "Booking is easy:\n".
            "1. Pick your check-in and check-out dates and number of guests, then hit Search to see available rooms.\n".
            "2. Log in or sign up if you haven't already — you'll need an account to reserve a room.\n".
            "3. Click \"Book this room\" on the one you want and confirm your dates and guests.\n".
            "You'll get an email confirming your reservation once it's submitted."
        );
    }

    protected function cancelHelp(): array
    {
        return $this->respond(
            "To cancel a reservation, go to \"My Bookings\" and cancel it from there. ".
            "You can only cancel your own bookings unless you're an admin."
        );
    }

    protected function fallback(): array
    {
        return $this->respond(
            "I'm not sure about that yet. Try asking about: room types & prices, availability for specific dates, ".
            "how to book, or how to cancel a reservation."
        );
    }

    /**
     * Ask an LLM (via OpenRouter) for anything the keyword rules above don't
     * cover. The room data is injected directly into the prompt and the
     * model is explicitly told to stick to it, so it can't invent a room
     * type, price, or policy that doesn't actually exist. Any failure
     * (missing key, network issue, bad response) degrades to fallback()
     * rather than surfacing an error to the guest.
     */
    protected function askLlm(string $message): array
    {
        $apiKey = config('services.openrouter.key');

        if (! $apiKey) {
            return $this->fallback();
        }

        $roomTypes = RoomType::query()->get(['name', 'description', 'base_price', 'capacity', 'amenities']);

        $context = $roomTypes->map(function (RoomType $type) {
            $amenities = collect($type->amenities)->implode(', ') ?: 'none listed';

            return "- {$type->name}: ₱".number_format((float) $type->base_price, 2).
                "/night, up to {$type->capacity} guest(s), amenities: {$amenities}. {$type->description}";
        })->implode("\n");

        $systemPrompt = "You are the booking assistant for CertiCode Hotel. Answer briefly (2-4 sentences), ".
            "in a friendly and professional tone. Only use the room information given below - never invent a ".
            "room type, price, or amenity that isn't listed. If asked something you can't answer from this ".
            "information (e.g. real-time availability for specific dates, an existing booking's status, or ".
            "anything outside hotel bookings), say so and suggest contacting the front desk or checking the ".
            "'My Bookings' page.\n\nCurrent room types:\n{$context}";

        // Free-tier models occasionally return an upstream 502 wrapped inside
        // a 200-status body (so it doesn't trip ->failed()) - worth one retry
        // before giving up, since a second attempt often just works.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $reply = $this->requestLlmReply($apiKey, $systemPrompt, $message);

            if ($reply !== null) {
                return $this->respond($reply);
            }
        }

        return $this->fallback();
    }

    protected function requestLlmReply(string $apiKey, string $systemPrompt, string $message): ?string
    {
        try {
            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => config('services.openrouter.model'),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $message],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('OpenRouter chatbot request failed', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $reply = $response->json('choices.0.message.content');

            if (! $reply) {
                Log::warning('OpenRouter chatbot returned no content', ['body' => $response->body()]);

                return null;
            }

            return trim($reply);
        } catch (\Throwable $e) {
            Log::warning('OpenRouter chatbot request threw an exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    protected function respond(string $text): array
    {
        return ['reply' => $text];
    }
}
