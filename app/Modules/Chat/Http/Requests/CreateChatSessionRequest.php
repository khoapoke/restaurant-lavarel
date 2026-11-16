<?php

namespace App\Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateChatSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'initialMessage' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'Tiêu đề phiên chat không được vượt quá 255 ký tự.',
            'initialMessage.max' => 'Tin nhắn mở đầu không được vượt quá 1000 ký tự.',
        ];
    }
}

