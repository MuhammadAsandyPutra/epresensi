<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PenggunaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $pengguna = $this->route('pengguna');

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($pengguna?->getKey())],
            'role' => ['required', Rule::in(array_keys(User::ROLE_LABEL))],
            'password' => [$pengguna ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama', 'password' => 'kata sandi'];
    }
}
