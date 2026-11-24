<?php

namespace App\Enums;

/** Disimpan di kolom pengajuan_izin.status (1 = izin, 2 = sakit — kompatibel dengan data lama). */
enum JenisPengajuan: int
{
    case Izin = 1;
    case Sakit = 2;
    case Cuti = 3;
    case TugasLuar = 4;

    public function label(): string
    {
        return match ($this) {
            self::Izin => 'Izin',
            self::Sakit => 'Sakit',
            self::Cuti => 'Cuti',
            self::TugasLuar => 'Tugas Luar',
        };
    }

    public function warna(): string
    {
        return match ($this) {
            self::Izin => 'info',
            self::Sakit => 'warning',
            self::Cuti => 'primary',
            self::TugasLuar => 'purple',
        };
    }

    /** Izin & sakit dibatasi kuota bulanan; cuti dibatasi saldo tahunan; tugas luar tidak dibatasi. */
    public function memakaiKuotaBulanan(): bool
    {
        return $this === self::Izin || $this === self::Sakit;
    }

    public function memakaiSaldoCuti(): bool
    {
        return $this === self::Cuti;
    }

    /**
     * Izin/sakit/cuti membebaskan karyawan dari presensi. Tugas luar TIDAK: karyawan tetap
     * presensi (selfie + GPS) dari lokasi tugas; yang dilewati hanya pengecekan radius kantor.
     */
    public function membebaskanPresensi(): bool
    {
        return $this !== self::TugasLuar;
    }
}
