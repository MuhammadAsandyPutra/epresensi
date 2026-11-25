<?php

namespace App\Services;

use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\Presensi;
use App\Models\PresensiTahan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Review presensi luar kantor yang DITAHAN. Disetujui Direksi => isinya dipindah ke tabel `presensi`
 * dengan jam asli saat karyawan presensi (terlambat/pulang cepat dihitung dari jam itu). Ditolak =>
 * tidak pernah tercatat. HR/Admin hanya menandai "diketahui".
 */
class PresensiLuarService
{
    public function putuskan(PresensiTahan $tahan, StatusPengajuan $keputusan, User $user, ?string $catatan = null): void
    {
        if (! $user->bolehMemutuskan()) {
            throw new AturanBisnisException('Hanya Direksi yang dapat menyetujui atau menolak presensi luar kantor.');
        }
        if (! in_array($keputusan, [StatusPengajuan::Disetujui, StatusPengajuan::Ditolak], true)) {
            throw new AturanBisnisException('Keputusan tidak valid.');
        }
        if ($tahan->status !== StatusPengajuan::Menunggu) {
            throw new AturanBisnisException('Presensi ini sudah diputuskan.');
        }

        DB::transaction(function () use ($tahan, $keputusan, $user, $catatan) {
            if ($keputusan === StatusPengajuan::Disetujui) {
                $this->terapkan($tahan, $user);
            }

            $tahan->update([
                'status' => $keputusan->value,
                'catatan' => $catatan,
                'diputuskan_oleh' => $user->getKey(),
                'diputuskan_pada' => now(),
            ]);
        });
    }

    /**
     * Hanya yang DITOLAK yang dapat dikembalikan ke Menunggu. Yang sudah disetujui sudah menjadi presensi
     * resmi; perubahannya lewat menu Koreksi Presensi agar jejaknya jelas.
     */
    public function kembalikanKeMenunggu(PresensiTahan $tahan): void
    {
        if ($tahan->status !== StatusPengajuan::Ditolak) {
            throw new AturanBisnisException('Hanya presensi yang ditolak yang dapat dikembalikan ke Menunggu. Yang sudah disetujui diubah lewat Koreksi Presensi.');
        }

        $tahan->update(['status' => StatusPengajuan::Menunggu->value, 'catatan' => null, 'diputuskan_oleh' => null, 'diputuskan_pada' => null]);
    }

    public function tandaiDiketahui(PresensiTahan $tahan, User $user): void
    {
        if (! $user->penerimaInfo()) {
            throw new AturanBisnisException('Hanya HR atau Admin yang dapat menandai presensi sebagai diketahui.');
        }
        if ($tahan->sudahDiketahui()) {
            return;
        }

        $tahan->update(['diketahui_oleh' => $user->getKey(), 'diketahui_pada' => now()]);
    }

    /** Pindahkan isi presensi tertahan ke tabel presensi, memakai jam asli. Dipanggil di dalam transaksi. */
    private function terapkan(PresensiTahan $tahan, User $user): void
    {
        $tanggal = $tahan->tanggal_presensi->toDateString();
        $presensi = Presensi::query()->where('nik', $tahan->nik)->where('tanggal_presensi', $tanggal)->lockForUpdate()->first();

        if ($tahan->jam_in !== null) {
            if ($presensi?->jam_in !== null) {
                throw new AturanBisnisException('Sudah ada presensi masuk pada tanggal ini (mis. hasil Koreksi HR), sehingga presensi tertahan tidak dapat diterapkan. Tolak, atau selesaikan lewat Koreksi Presensi.');
            }

            $masuk = [
                'jam_in' => $tahan->jam_in,
                'foto_in' => $tahan->foto_in,
                'lokasi_in' => $tahan->lokasi_in,
                'jarak_in' => $tahan->jarak_in,
                'akurasi_in' => $tahan->akurasi_in,
                'kode_jam_kerja' => $tahan->kode_jam_kerja,
                'ip_address' => $tahan->ip_address,
                'luar_kantor' => true,
                'perlu_review' => false,
                'catatan' => 'Presensi luar kantor, disetujui Direksi',
            ];
            $presensi = $presensi
                ? tap($presensi)->update($masuk)
                : Presensi::query()->create($masuk + ['nik' => $tahan->nik, 'tanggal_presensi' => $tanggal]);
        }

        if ($tahan->jam_out !== null) {
            if ($presensi === null || $presensi->jam_in === null) {
                throw new AturanBisnisException('Presensi masuk belum tercatat pada tanggal ini, sehingga presensi pulang tidak dapat diterapkan.');
            }
            if ($presensi->jam_out !== null) {
                throw new AturanBisnisException('Sudah ada presensi pulang pada tanggal ini (mis. hasil Koreksi HR), sehingga presensi tertahan tidak dapat diterapkan. Tolak, atau selesaikan lewat Koreksi Presensi.');
            }

            $pulang = [
                'jam_out' => $tahan->jam_out,
                'foto_out' => $tahan->foto_out,
                'lokasi_out' => $tahan->lokasi_out,
                'jarak_out' => $tahan->jarak_out,
                'luar_kantor' => true,
            ];

            // Keputusan atas presensi ini mencakup lembur / pulang lebih awal di dalamnya.
            $disetujui = [
                'diputuskan_oleh' => $user->getKey(), 'diputuskan_pada' => now(),
            ];
            if ($tahan->lembur_menit) {
                $pulang += [
                    'lembur_menit' => $tahan->lembur_menit,
                    'keterangan_lembur' => $tahan->keterangan_lembur,
                    'lembur_status' => StatusPengajuan::Disetujui->value,
                    'lembur_catatan' => 'Disetujui bersama presensi luar kantor',
                    'lembur_diputuskan_oleh' => $disetujui['diputuskan_oleh'],
                    'lembur_diputuskan_pada' => $disetujui['diputuskan_pada'],
                    'lembur_diketahui_oleh' => $tahan->diketahui_oleh,
                    'lembur_diketahui_pada' => $tahan->diketahui_pada,
                ];
            }
            if ($tahan->pulang_cepat_menit) {
                $pulang += [
                    'pulang_cepat_menit' => $tahan->pulang_cepat_menit,
                    'alasan_pulang_cepat' => $tahan->alasan_pulang_cepat,
                    'pulang_cepat_status' => StatusPengajuan::Disetujui->value,
                    'pulang_cepat_catatan' => 'Disetujui bersama presensi luar kantor',
                    'pulang_cepat_diputuskan_oleh' => $disetujui['diputuskan_oleh'],
                    'pulang_cepat_diputuskan_pada' => $disetujui['diputuskan_pada'],
                    'pulang_cepat_diketahui_oleh' => $tahan->diketahui_oleh,
                    'pulang_cepat_diketahui_pada' => $tahan->diketahui_pada,
                ];
            }
            $presensi->update($pulang);
        }
    }
}
