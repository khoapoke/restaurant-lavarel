<?php

namespace App\Modules\Menu\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateDishRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dishName' => ['required', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'imageUrl' => ['nullable', 'string', 'max:500'],
            'categoryId' => ['required', 'integer', 'exists:DANH_MUC,ma_danh_muc'],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*' => ['string'],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string'],
            'weatherTags' => ['nullable', 'array'],
            'weatherTags.*' => ['string'],
            'isAvailable' => ['nullable', 'boolean'],
            'isVisible' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categoryId.exists' => 'The selected category id does not exist in DANH_MUC.',
        ];
    }
}

