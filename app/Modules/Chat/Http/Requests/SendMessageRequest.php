<?php

namespace App\Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => 'required|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Nội dung tin nhắn không được để trống.',
            'content.max' => 'Nội dung tin nhắn không được vượt quá 2000 ký tự.',
        ];
    }
}

