<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:TAI_KHOAN,ten_dang_nhap'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'passwordConfirmation' => ['required', 'string', 'same:password'],
            'email' => ['required', 'string', 'email', 'max:100', 'unique:TAI_KHOAN,email'],
            'fullName' => ['required', 'string', 'max:100'],
            'phoneNumber' => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9,10}$/'],
            'birthDate' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
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
            'username.unique' => 'The username has already been taken.',
            'email.unique' => 'The email has already been taken.',
            'phoneNumber.regex' => 'The phone number format is invalid.',
            'passwordConfirmation.same' => 'The password confirmation does not match.',
        ];
    }
}

