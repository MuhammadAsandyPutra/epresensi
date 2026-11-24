<?php

namespace App\Services;

use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\Presensi;
use App\Models\User;

/**
 * Keputusan pulang lebih awal. Tercatat otomatis di baris presensi (lihat PresensiService::catat) dengan
 * status "Menunggu"; Direksi memutuskan SESUDAH karyawan pulang, HR/Admin hanya menandai "diketahui".
 * Yang disetujui tidak dihitung sebagai pulang cepat di KPI (lihat RekapService).
 */
class PulangCepatService
{
    /** Direksi menyetujui/menolak pulang cepat yang menunggu. */
    public function putuskan(Presensi $presensi, StatusPengajuan $keputusan, User $user, ?string $catatan = null): void
    {
        if (! $user->bolehMemutuskan()) {
            throw new AturanBisnisException('Hanya Direksi yang dapat menyetujui atau menolak pulang cepat.');
        }
        if (! $presensi->adaPulangCepat()) {
            throw new AturanBisnisException('Presensi ini tidak memiliki pengajuan pulang cepat.');
        }
        if (! in_array($keputusan, [StatusPengajuan::Disetujui, StatusPengajuan::Ditolak], true)) {
            throw new AturanBisnisException('Keputusan tidak valid.');
        }
        if ($presensi->pulang_cepat_status !== StatusPengajuan::Menunggu) {
            throw new AturanBisnisException('Pulang cepat ini sudah diputuskan. Kembalikan ke "Menunggu" bila ingin mengubahnya.');
        }

        $presensi->update([
            'pulang_cepat_status' => $keputusan->value,
            'pulang_cepat_catatan' => $catatan,
            'pulang_cepat_diputuskan_oleh' => $user->getKey(),
            'pulang_cepat_diputuskan_pada' => now(),
        ]);
    }

    /** Direksi membatalkan keputusan sebelumnya (kembali ke Menunggu). */
    public function kembalikanKeMenunggu(Presensi $presensi): void
    {
        if (! in_array($presensi->pulang_cepat_status, [StatusPengajuan::Disetujui, StatusPengajuan::Ditolak], true)) {
            throw new AturanBisnisException('Hanya pulang cepat yang sudah diputuskan yang dapat dikembalikan.');
        }

        $presensi->update([
            'pulang_cepat_status' => StatusPengajuan::Menunggu->value,
            'pulang_cepat_catatan' => null,
            'pulang_cepat_diputuskan_oleh' => null,
            'pulang_cepat_diputuskan_pada' => null,
        ]);
    }

    /** HR/Admin menandai pulang cepat sudah diketahui (pemberitahuan, bukan persetujuan). */
    public function tandaiDiketahui(Presensi $presensi, User $user): void
    {
        if (! $user->penerimaInfo()) {
            throw new AturanBisnisException('Hanya HR atau Admin yang dapat menandai pulang cepat sebagai diketahui.');
        }
        if (! $presensi->adaPulangCepat()) {
            throw new AturanBisnisException('Presensi ini tidak memiliki pengajuan pulang cepat.');
        }
        if ($presensi->pulang_cepat_diketahui_pada !== null) {
            return;
        }

        $presensi->update(['pulang_cepat_diketahui_oleh' => $user->getKey(), 'pulang_cepat_diketahui_pada' => now()]);
    }
}
