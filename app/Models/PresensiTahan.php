<?php

namespace App\Models;

use App\Enums\StatusPengajuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Presensi dari luar kantor yang DITAHAN menunggu review Direksi. Belum dihitung di rekap/KPI sampai
 * disetujui; saat itu isinya dipindah ke tabel `presensi` dengan jam asli (lihat PresensiLuarService).
 */
class PresensiTahan extends Model
{
    protected $table = 'presensi_tahan';

    protected $fillable = [
        'nik', 'tanggal_presensi', 'kode_jam_kerja',
        'jam_in', 'foto_in', 'lokasi_in', 'jarak_in', 'akurasi_in',
        'jam_out', 'foto_out', 'lokasi_out', 'jarak_out',
        'alasan', 'lembur_menit', 'keterangan_lembur', 'pulang_cepat_menit', 'alasan_pulang_cepat', 'ip_address',
        'status', 'catatan', 'diputuskan_oleh', 'diputuskan_pada', 'diketahui_oleh', 'diketahui_pada',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPengajuan::class,
            'diputuskan_pada' => 'datetime',
            'diketahui_pada' => 'datetime',
        ];
    }

    /**
     * Dibaca sebagai Carbon, tetapi sengaja BUKAN cast `date`: cast itu menulis "Y-m-d 00:00:00" sehingga
     * pencarian `where tanggal_presensi = 'Y-m-d'` gagal di SQLite (sama seperti model Presensi).
     */
    protected function tanggalPresensi(): Attribute
    {
        return Attribute::get(fn ($nilai) => $nilai ? Carbon::parse($nilai) : null);
    }

    public function scopeMenunggu(Builder $q): Builder
    {
        return $q->where('status', StatusPengajuan::Menunggu->value);
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'nik', 'nik');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    public function sudahDiketahui(): bool
    {
        return $this->diketahui_pada !== null;
    }
}
