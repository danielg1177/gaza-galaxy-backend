<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:32', 'unique:users', 'regex:/^[a-zA-Z0-9_]+$/'],
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}
