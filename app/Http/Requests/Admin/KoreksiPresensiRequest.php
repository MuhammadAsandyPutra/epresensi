<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KoreksiPresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nik' => ['required', 'string', 'max:20', 'exists:karyawan,nik'],
            'tanggal' => ['required', 'date_format:Y-m-d'],   // batas tanggal dicek di service (memakai jam aplikasi)
            'jam_masuk' => ['nullable', 'date_format:H:i'],
            'jam_pulang' => ['nullable', 'date_format:H:i'],
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['nik' => 'karyawan', 'jam_masuk' => 'jam masuk', 'jam_pulang' => 'jam pulang'];
    }
}
