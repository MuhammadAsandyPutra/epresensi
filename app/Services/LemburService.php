<?php

namespace App\Services;

use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\Presensi;
use App\Models\User;

/**
 * Persetujuan lembur. Lembur tercatat otomatis di baris presensi (lihat PresensiService::catat)
 * dengan status "Menunggu"; Direksi memutuskan, HR/Admin hanya menandai "diketahui".
 */
class LemburService
{
    /** Direksi menyetujui/menolak lembur yang menunggu. */
    public function putuskan(Presensi $presensi, StatusPengajuan $keputusan, User $user, ?string $catatan = null): void
    {
        if (! $user->bolehMemutuskan()) {
            throw new AturanBisnisException('Hanya Direksi yang dapat menyetujui atau menolak lembur.');
        }
        if (! $presensi->adaLembur()) {
            throw new AturanBisnisException('Presensi ini tidak memiliki pengajuan lembur.');
        }
        if (! in_array($keputusan, [StatusPengajuan::Disetujui, StatusPengajuan::Ditolak], true)) {
            throw new AturanBisnisException('Keputusan tidak valid.');
        }
        if ($presensi->lembur_status !== StatusPengajuan::Menunggu) {
            throw new AturanBisnisException('Lembur ini sudah diputuskan. Kembalikan ke "Menunggu" bila ingin mengubahnya.');
        }

        $presensi->update([
            'lembur_status' => $keputusan->value,
            'lembur_catatan' => $catatan,
            'lembur_diputuskan_oleh' => $user->getKey(),
            'lembur_diputuskan_pada' => now(),
        ]);
    }

    /** Direksi membatalkan keputusan sebelumnya (kembali ke Menunggu). */
    public function kembalikanKeMenunggu(Presensi $presensi): void
    {
        if (! in_array($presensi->lembur_status, [StatusPengajuan::Disetujui, StatusPengajuan::Ditolak], true)) {
            throw new AturanBisnisException('Hanya lembur yang sudah diputuskan yang dapat dikembalikan.');
        }

        $presensi->update([
            'lembur_status' => StatusPengajuan::Menunggu->value,
            'lembur_catatan' => null,
            'lembur_diputuskan_oleh' => null,
            'lembur_diputuskan_pada' => null,
        ]);
    }

    /** HR/Admin menandai lembur sudah diketahui (pemberitahuan, bukan persetujuan). */
    public function tandaiDiketahui(Presensi $presensi, User $user): void
    {
        if (! $user->penerimaInfo()) {
            throw new AturanBisnisException('Hanya HR atau Admin yang dapat menandai lembur sebagai diketahui.');
        }
        if (! $presensi->adaLembur()) {
            throw new AturanBisnisException('Presensi ini tidak memiliki pengajuan lembur.');
        }
        if ($presensi->lembur_diketahui_pada !== null) {
            return;
        }

        $presensi->update(['lembur_diketahui_oleh' => $user->getKey(), 'lembur_diketahui_pada' => now()]);
    }
}
