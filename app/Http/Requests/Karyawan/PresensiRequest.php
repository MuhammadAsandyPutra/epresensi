<?php

namespace App\Http\Requests\Karyawan;

use Illuminate\Foundation\Http\FormRequest;

class PresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lokasi' => ['required', 'string', 'max:60'],   // "lat,lng"
            'akurasi' => ['nullable', 'numeric', 'min:0'],  // meter
            // Data URL base64 (~4/3 ukuran biner). Dibatasi SEBELUM didekode agar kiriman raksasa tidak memenuhi memori.
            'foto' => ['required', 'string', 'max:'.(int) ceil(config('presensi.foto.maks_kb') * 1024 * 4 / 3 + 200)],
            'keterangan_lembur' => ['nullable', 'string', 'max:500'], // wajib bila pulang melewati batas lembur (dicek di service)
            'alasan_pulang_cepat' => ['nullable', 'string', 'max:500'], // wajib bila pulang sebelum jam pulang (dicek di service)
            'alasan_luar_kantor' => ['nullable', 'string', 'max:500'],  // tujuan, wajib bila presensi di luar radius (dicek di service)
        ];
    }

    public function messages(): array
    {
        return ['lokasi.required' => 'Lokasi belum terbaca. Aktifkan GPS lalu coba lagi.', 'foto.required' => 'Foto selfie belum diambil.'];
    }
}
