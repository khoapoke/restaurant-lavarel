<?php

namespace App\Http\Requests\Staff;

use App\Services\Staff\PosSessionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeSessionTablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tableIds' => ['required', 'array', 'min:1'],
            'tableIds.*' => ['required', 'integer', 'distinct', 'exists:BAN_AN,ma_ban'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'releaseAs' => ['nullable', Rule::in(PosSessionService::RELEASE_MODES)],
        ];
    }
}
