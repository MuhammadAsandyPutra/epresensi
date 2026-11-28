<?php

namespace App\Http\Requests\Karyawan;

use Illuminate\Foundation\Http\FormRequest;

/** Presensi dengan QR: tanpa foto. Token diperiksa di controller; lokasi dan jam divalidasi di PresensiService. */
class PresensiQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:80'],
            'lokasi' => ['required', 'string', 'max:60'],
            'akurasi' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'keterangan_lembur' => ['nullable', 'string', 'max:500'],
            'alasan_pulang_cepat' => ['nullable', 'string', 'max:500'],
        ];
    }
}
