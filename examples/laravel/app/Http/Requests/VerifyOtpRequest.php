<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'min:3', 'max:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'Enter the code you received.',
        ];
    }
}
