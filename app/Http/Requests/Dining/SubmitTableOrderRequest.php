<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A QR ordering round. Only dish ids, quantities and notes are accepted; prices are
 * never read from the request.
 */
class SubmitTableOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotencyKey' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxLines = (int) config('dining.ordering.max_lines_per_round', 20);
        $maxQuantity = (int) config('dining.ordering.max_quantity_per_line', 20);

        return [
            'idempotencyKey' => ['required', 'string', 'min:12', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'items' => ['required', 'array', 'min:1', "max:{$maxLines}"],
            'items.*.dishId' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', "max:{$maxQuantity}"],
            'items.*.note' => ['nullable', 'string', 'max:120'],
            'expectedTotal' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotencyKey.required' => 'Idempotency-Key header is required.',
        ];
    }
}
