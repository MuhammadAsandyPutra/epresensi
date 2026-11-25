<?php

namespace App\Models;

use App\Enums\StatusPengajuan;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Presensi extends Model
{
    protected $table = 'presensi';

    public $timestamps = false;

    protected $fillable = [
        'nik', 'tanggal_presensi', 'jam_in', 'jam_out', 'foto_in', 'foto_out',
        'lokasi_in', 'lokasi_out', 'kode_jam_kerja', 'jarak_in', 'jarak_out',
        'akurasi_in', 'perlu_review', 'catatan', 'ip_address', 'tugas_luar',
        'lembur_menit', 'keterangan_lembur', 'lembur_status', 'lembur_catatan',
        'lembur_diputuskan_oleh', 'lembur_diputuskan_pada', 'lembur_diketahui_oleh', 'lembur_diketahui_pada',
        'pulang_cepat_menit', 'alasan_pulang_cepat', 'pulang_cepat_status', 'pulang_cepat_catatan',
        'pulang_cepat_diputuskan_oleh', 'pulang_cepat_diputuskan_pada', 'pulang_cepat_diketahui_oleh', 'pulang_cepat_diketahui_pada',
        'koreksi_oleh', 'koreksi_pada', 'koreksi_alasan', 'koreksi_jam_in_asli', 'koreksi_jam_out_asli',
        'luar_kantor',
    ];

    protected function casts(): array
    {
        // Catatan: kolom tanggal SENGAJA tidak di-cast 'date' (Laravel menyimpannya sebagai
        // "Y-m-d 00:00:00" sehingga perbandingan string di SQLite meleset). Dibaca lewat accessor.
        return [
            'perlu_review' => 'boolean',
            'tugas_luar' => 'boolean',
            'lembur_status' => StatusPengajuan::class, // null = tidak lembur
            'lembur_diputuskan_pada' => 'datetime',
            'lembur_diketahui_pada' => 'datetime',
            'pulang_cepat_status' => StatusPengajuan::class, // null = pulang normal
            'pulang_cepat_diputuskan_pada' => 'datetime',
            'pulang_cepat_diketahui_pada' => 'datetime',
            'koreksi_pada' => 'datetime',
            'luar_kantor' => 'boolean',
        ];
    }

    protected function tanggalPresensi(): Attribute
    {
        return Attribute::get(fn ($nilai) => $nilai ? Carbon::parse($nilai) : null);
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'nik', 'nik');
    }

    public function jamKerja(): BelongsTo
    {
        return $this->belongsTo(JamKerja::class, 'kode_jam_kerja', 'kode_jam_kerja');
    }

    /** Direksi yang memutuskan lembur. */
    public function pemutusLembur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lembur_diputuskan_oleh');
    }

    /** Direksi yang memutuskan pulang cepat. */
    public function pemutusPulangCepat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pulang_cepat_diputuskan_oleh');
    }

    /** HR/Admin yang mengoreksi presensi ini. */
    public function pengoreksi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'koreksi_oleh');
    }

    public function dikoreksi(): bool
    {
        return $this->koreksi_pada !== null;
    }

    public function adaPulangCepat(): bool
    {
        return $this->pulang_cepat_status !== null;
    }

    public function adaLembur(): bool
    {
        return $this->lembur_status !== null;
    }

    public function menitTerlambat(): int
    {
        return $this->jamKerja?->menitTerlambat($this->jam_in) ?? 0;
    }

    public function pulangCepat(): bool
    {
        return $this->jamKerja?->pulangCepat($this->jam_out) ?? false;
    }
}
