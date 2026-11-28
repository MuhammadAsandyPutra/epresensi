<?php

namespace App\Services;

use App\Enums\JenisPengajuan;
use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\HariLibur;
use App\Models\Karyawan;
use App\Models\PengajuanIzin;
use App\Models\Pengaturan;
use App\Models\Presensi;
use App\Models\User;
use App\Support\Waktu;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Koreksi presensi oleh HR/Admin: untuk karyawan yang sama sekali tidak bisa presensi (HP rusak,
 * aplikasi error, dll.) atau lupa presensi pulang. Membuat baris presensi bila belum ada, atau
 * mengubah jam pada baris yang ada. Jam asli disimpan, dan alasan serta pelakunya tercatat permanen.
 *
 * Jam pulang tidak dapat dikoreksi bila ada pengajuan lembur/pulang awal yang masih menunggu atau sudah disetujui; yang ditolak
 * tidak menghalangi (dihapus saat jam pulang dikoreksi).
 *
 * Catatan KPI: koreksi hanya mengisi jam. Bila jam pulang yang dikoreksi lebih awal dari jam pulang
 * jadwal, hari itu tetap dihitung pulang cepat (tidak ada pengajuan yang menyetujuinya).
 */
class KoreksiPresensiService
{
    public function __construct(private JadwalService $jadwal) {}

    /**
     * @param  string  $tanggal  Y-m-d
     * @param  string|null  $jamMasuk  H:i atau H:i:s (null = tidak diubah)
     * @param  string|null  $jamPulang  H:i atau H:i:s (null = tidak diubah)
     *
     * @throws AturanBisnisException
     */
    public function koreksi(Karyawan $karyawan, string $tanggal, ?string $jamMasuk, ?string $jamPulang, string $alasan, User $user): Presensi
    {
        if (! $user->penerimaInfo()) {
            throw new AturanBisnisException('Hanya HR atau Admin yang dapat mengoreksi presensi.');
        }

        $alasan = trim($alasan);
        if (mb_strlen($alasan) < 10) {
            throw new AturanBisnisException('Alasan koreksi wajib diisi (minimal 10 karakter).');
        }

        $jamMasuk = $this->rapikan($jamMasuk);
        $jamPulang = $this->rapikan($jamPulang);
        if ($jamMasuk === null && $jamPulang === null) {
            throw new AturanBisnisException('Isi jam masuk dan/atau jam pulang yang akan dikoreksi.');
        }

        $tgl = Carbon::parse($tanggal)->startOfDay();
        $hariIni = now()->startOfDay();
        if ($tgl->gt($hariIni)) {
            throw new AturanBisnisException('Tanggal koreksi tidak boleh di masa depan.');
        }
        $maks = (int) Pengaturan::ambil('koreksi.maks_hari_mundur');
        if ($tgl->lt($hariIni->copy()->subDays($maks))) {
            throw new AturanBisnisException("Koreksi hanya dapat dilakukan untuk {$maks} hari terakhir.");
        }

        $tglStr = $tgl->toDateString();
        if (HariLibur::query()->where('tanggal', $tglStr)->exists()) {
            throw new AturanBisnisException('Tanggal tersebut hari libur; tidak ada presensi yang perlu dikoreksi.');
        }
        $jamKerja = $this->jadwal->untukTanggal($karyawan, $tgl)
            ?? throw new AturanBisnisException('Tanggal tersebut bukan hari kerja karyawan ini.');

        $izin = PengajuanIzin::query()
            ->where('nik', $karyawan->nik)
            ->where('status_approved', StatusPengajuan::Disetujui->value)
            ->where('status', '!=', JenisPengajuan::TugasLuar->value)
            ->berimpitDengan($tglStr, $tglStr)
            ->first();
        if ($izin) {
            throw new AturanBisnisException("Tanggal tersebut berstatus {$izin->jenis()->label()} yang sudah disetujui.");
        }

        $presensi = Presensi::query()->where('nik', $karyawan->nik)->where('tanggal_presensi', $tglStr)->first();

        $masukAkhir = $jamMasuk ?? $presensi?->jam_in;
        if ($masukAkhir === null) {
            throw new AturanBisnisException('Jam masuk wajib diisi karena belum ada presensi masuk pada tanggal tersebut.');
        }
        $pulangAkhir = $jamPulang ?? $presensi?->jam_out;
        if ($pulangAkhir !== null && Waktu::detik($pulangAkhir) <= Waktu::detik($masukAkhir)) {
            throw new AturanBisnisException('Jam pulang harus lebih akhir dari jam masuk.');
        }
        // Jam pulang yang punya pengajuan lembur/pulang awal: yang masih MENUNGGU atau sudah DISETUJUI menahan koreksi
        // (keputusan Direksi harus selesai/utuh dulu). Yang DITOLAK tidak menghalangi: pengajuannya gugur dan dibersihkan.
        $hapusTolakan = [];
        if ($jamPulang !== null && $presensi) {
            foreach (['lembur' => $presensi->lembur_status, 'pulang lebih awal' => $presensi->pulang_cepat_status] as $nama => $status) {
                if ($status === StatusPengajuan::Menunggu) {
                    throw new AturanBisnisException("Jam pulang belum dapat dikoreksi: pengajuan {$nama} pada presensi ini masih menunggu keputusan Direksi.");
                }
                if ($status === StatusPengajuan::Disetujui) {
                    throw new AturanBisnisException("Jam pulang tidak dapat dikoreksi: pengajuan {$nama} pada presensi ini sudah disetujui Direksi.");
                }
            }
            if ($presensi->lembur_status === StatusPengajuan::Ditolak) {
                $hapusTolakan += $this->kosongkan('lembur', ['menit', 'status', 'catatan', 'diputuskan_oleh', 'diputuskan_pada', 'diketahui_oleh', 'diketahui_pada'], ['keterangan_lembur']);
            }
            if ($presensi->pulang_cepat_status === StatusPengajuan::Ditolak) {
                $hapusTolakan += $this->kosongkan('pulang_cepat', ['menit', 'status', 'catatan', 'diputuskan_oleh', 'diputuskan_pada', 'diketahui_oleh', 'diketahui_pada'], ['alasan_pulang_cepat']);
            }
        }

        $koreksi = ['koreksi_oleh' => $user->getKey(), 'koreksi_pada' => now(), 'koreksi_alasan' => mb_substr($alasan, 0, 500)];

        if ($presensi === null) {
            try {
                return Presensi::query()->create([
                    'nik' => $karyawan->nik,
                    'tanggal_presensi' => $tglStr,
                    'jam_in' => $masukAkhir,
                    'jam_out' => $pulangAkhir,
                    'kode_jam_kerja' => $jamKerja->kode_jam_kerja,
                    'perlu_review' => false,
                    'catatan' => 'Dibuat melalui koreksi HR',
                ] + $koreksi);
            } catch (UniqueConstraintViolationException) {
                throw new AturanBisnisException('Presensi karyawan ini baru saja tercatat. Muat ulang halaman lalu coba lagi.');
            }
        }

        // Jam asli hanya disimpan pada koreksi PERTAMA; koreksi berikutnya tidak menimpanya.
        if (! $presensi->dikoreksi()) {
            $koreksi += ['koreksi_jam_in_asli' => $presensi->jam_in, 'koreksi_jam_out_asli' => $presensi->jam_out];
        }
        if ($hapusTolakan !== []) {
            // Jejak tetap ada: keputusan menolak tercatat di Log Aktivitas, dan catatan ini ikut di alasan koreksi.
            $koreksi['koreksi_alasan'] = mb_substr($alasan.' [Sistem: pengajuan lembur/pulang awal yang ditolak dihapus karena jam pulang dikoreksi]', 0, 500);
        }
        $presensi->update(['jam_in' => $masukAkhir, 'jam_out' => $pulangAkhir] + $koreksi + $hapusTolakan);

        return $presensi;
    }

    /**
     * Daftar kolom presensi yang dikosongkan untuk satu jenis pengajuan (mis. pulang_cepat_menit => null).
     *
     * @param  string[]  $akhiran  mis. ['menit', 'status'] menjadi "{$awalan}_menit", "{$awalan}_status"
     * @param  string[]  $lain  kolom lain yang ikut dikosongkan apa adanya
     * @return array<string, null>
     */
    private function kosongkan(string $awalan, array $akhiran, array $lain): array
    {
        $kolom = [];
        foreach ($akhiran as $a) {
            $kolom["{$awalan}_{$a}"] = null;
        }
        foreach ($lain as $k) {
            $kolom[$k] = null;
        }

        return $kolom;
    }

    /** "8:05" / "08:05" / "08:05:30" => "08:05:00"; kosong => null. */
    private function rapikan(?string $jam): ?string
    {
        $jam = trim((string) $jam);
        if ($jam === '') {
            return null;
        }
        $b = explode(':', $jam);

        return sprintf('%02d:%02d:00', (int) $b[0], (int) ($b[1] ?? 0));
    }
}
