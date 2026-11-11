<?php

namespace App\Modules\Auth\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
        $accountId = $this->user()?->ma_tai_khoan;

        return [
            'fullName' => ['sometimes', 'required', 'string', 'max:100'],
            'birthDate' => ['nullable', 'date', 'before:today'],
            'phoneNumber' => ['sometimes', 'required', 'string', 'regex:/^(0|\+84)[0-9]{9,10}$/'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:100',
                Rule::unique('TAI_KHOAN', 'email')->ignore($accountId, 'ma_tai_khoan'),
            ],
            'defaultAddress' => ['nullable', 'string', 'max:255'],
            'savedAddresses' => ['nullable', 'array'],
            'savedAddresses.*.addressId' => ['nullable', 'string'],
            'savedAddresses.*.recipientName' => ['nullable', 'string', 'max:100'],
            'savedAddresses.*.recipientPhone' => ['nullable', 'string'],
            'savedAddresses.*.fullAddress' => ['nullable', 'string', 'max:255'],
            'savedAddresses.*.isDefault' => ['nullable', 'boolean'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:50'],
            'dietaryPreferences' => ['nullable', 'array'],
            'dietaryPreferences.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'The email has already been taken.',
            'phoneNumber.regex' => 'The phone number format is invalid.',
        ];
    }
}

