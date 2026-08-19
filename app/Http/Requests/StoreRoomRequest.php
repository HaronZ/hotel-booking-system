<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already gated by auth:api + admin middleware.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'room_number' => [
                'required', 'string', 'max:20',
                // ignore($this->route('room')) excludes the room's own row when
                // this request is reused for update() - it's null (no-op) on
                // store(), so create still enforces uniqueness normally.
                Rule::unique('rooms', 'room_number')->ignore($this->route('room')),
            ],
            'floor' => ['nullable', 'integer', 'min:0', 'max:255'],
            'status' => ['nullable', 'in:available,maintenance,inactive'],
        ];
    }
}
