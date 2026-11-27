<?php

namespace App\Services;

use App\Enums\JenisPengajuan;
use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\HariLibur;
use App\Models\Karyawan;
use App\Models\PengajuanIzin;
use App\Models\Pengaturan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/** Aturan pengajuan izin, sakit, dan cuti beserta alur persetujuannya. */
class PengajuanService
{
    /** Keterangan minimal (karakter) agar izin/sakit dianggap berdokumen, selain lampiran surat. */
    private const KETERANGAN_MIN = 5;

    /** Berapa tahun ke belakang luapan (pinjaman) cuti dihitung; sekaligus pagar agar perhitungan tidak berulang tanpa batas. */
    private const TAHUN_MUNDUR = 5;

    public function __construct(private JadwalService $jadwal) {}

    /**
     * Aturan saldo: izin/sakit TANPA kuota bulanan. Izin/sakit dengan keterangan DAN surat (lampiran) tidak memotong
     * cuti; selain itu memotong jatah cuti tahunan, sama seperti cuti. Bila sisa tahun ini tidak cukup, kekurangannya
     * dipinjam dari jatah tahun berikutnya (paling banyak sebesar sisa jatah tahun itu). Tugas luar tidak memotong.
     *
     * @param  array{jenis: int|string, tgl_izin: string, tgl_selesai?: string|null, keterangan?: string|null, lokasi_tugas?: string|null}  $data
     *
     * @throws AturanBisnisException
     */
    public function ajukan(Karyawan $karyawan, array $data, ?UploadedFile $lampiran = null): PengajuanIzin
    {
        $jenis = JenisPengajuan::from((int) $data['jenis']);
        $mulai = Carbon::parse($data['tgl_izin'])->startOfDay();
        $selesai = ! empty($data['tgl_selesai']) ? Carbon::parse($data['tgl_selesai'])->startOfDay() : $mulai->copy();
        $hariIni = now()->startOfDay();

        if ($selesai->lt($mulai)) {
            throw new AturanBisnisException('Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        // Batas rentang: hitungHariKerja() melangkah per hari, jadi rentang yang tidak masuk akal
        // (mis. salah ketik tahun 2926) ditolak di sini sebelum menghabiskan waktu/memori.
        $maksRentang = (int) config('presensi.izin.maks_rentang_hari');
        if ((int) abs($mulai->diffInDays($selesai)) > $maksRentang) {
            throw new AturanBisnisException("Rentang tanggal terlalu panjang (maksimal {$maksRentang} hari). Periksa kembali tanggal selesai.");
        }

        if ($jenis === JenisPengajuan::Sakit) {
            $mundur = (int) Pengaturan::ambil('izin.sakit_mundur_hari');
            if ($mulai->lt($hariIni->copy()->subDays($mundur))) {
                throw new AturanBisnisException("Pengajuan sakit hanya dapat dibuat mundur maksimal {$mundur} hari.");
            }
        } elseif ($mulai->lt($hariIni)) {
            throw new AturanBisnisException('Tanggal pengajuan tidak boleh sebelum hari ini.');
        }

        $hari = $this->hitungHariKerja($karyawan, $mulai, $selesai);
        if ($hari < 1) {
            throw new AturanBisnisException('Rentang tanggal tersebut tidak mengandung hari kerja Anda.');
        }

        $bentrok = PengajuanIzin::query()->where('nik', $karyawan->nik)->berlaku()
            ->berimpitDengan($mulai->toDateString(), $selesai->toDateString())->exists();
        if ($bentrok) {
            throw new AturanBisnisException('Sudah ada pengajuan lain pada tanggal tersebut.');
        }

        $keterangan = trim((string) ($data['keterangan'] ?? ''));
        $potongCuti = $this->memotongCuti($jenis, $keterangan, $lampiran);
        $pinjam = 0;
        if ($potongCuti) {
            if ($mulai->year !== $selesai->year) {
                throw new AturanBisnisException('Pengajuan yang memotong cuti tidak boleh lintas tahun; ajukan terpisah per tahun.');
            }
            $pinjam = $this->periksaSaldo($karyawan, $mulai->year, $hari, $jenis);
        }

        $lokasiTugas = null;
        if ($jenis === JenisPengajuan::TugasLuar) {
            $lokasiTugas = trim((string) ($data['lokasi_tugas'] ?? ''));
            if ($lokasiTugas === '') {
                throw new AturanBisnisException('Tujuan/lokasi tugas luar wajib diisi.');
            }
        }

        return PengajuanIzin::query()->create([
            'nik' => $karyawan->nik,
            'tgl_izin' => $mulai->toDateString(),
            'tgl_selesai' => $selesai->toDateString(),
            'status' => $jenis->value,
            'keterangan' => $keterangan !== '' ? $keterangan : null,
            'status_approved' => StatusPengajuan::Menunggu->value,
            'jumlah_hari' => $hari,
            'lokasi_tugas' => $lokasiTugas !== null ? mb_substr($lokasiTugas, 0, 150) : null,
            'potong_cuti' => $potongCuti,
            'hari_pinjam' => $pinjam,
            'lampiran' => $lampiran?->store('lampiran/'.$mulai->year, config('presensi.foto.disk')),
        ]);
    }

    /** Jumlah hari kerja KARYAWAN tsb dalam rentang (sesuai jadwal, tanpa hari libur). */
    public function hitungHariKerja(Karyawan $karyawan, CarbonInterface $mulai, CarbonInterface $selesai): int
    {
        $peta = $this->jadwal->petaUntuk($karyawan);
        $libur = HariLibur::query()->whereBetween('tanggal', [$mulai->toDateString(), $selesai->toDateString()])
            ->get()->map(fn ($h) => $h->tanggal->toDateString())->all();

        $jumlah = 0;
        for ($tgl = $mulai->copy(); $tgl->lte($selesai); $tgl->addDay()) {
            $namaHari = config('presensi.hari')[$tgl->dayOfWeekIso];
            if (isset($peta[$namaHari]) && ! in_array($tgl->toDateString(), $libur, true)) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /**
     * Apakah pengajuan ini memotong jatah cuti tahunan? Cuti: ya. Tugas luar: tidak. Izin/sakit: bebas potong
     * HANYA bila ada keterangan memadai DAN surat/lampiran; selain itu memotong.
     */
    private function memotongCuti(JenisPengajuan $jenis, string $keterangan, ?UploadedFile $lampiran): bool
    {
        return match ($jenis) {
            JenisPengajuan::Cuti => true,
            JenisPengajuan::TugasLuar => false,
            JenisPengajuan::Izin, JenisPengajuan::Sakit => ! ($lampiran !== null && mb_strlen($keterangan) >= self::KETERANGAN_MIN),
        };
    }

    /**
     * Pastikan saldo cukup untuk $hari hari yang memotong cuti pada $tahun. Yang melebihi sisa tahun ini
     * dipinjam dari jatah tahun berikutnya, paling banyak sebesar sisa jatah tahun itu. Mengembalikan
     * jumlah hari yang dipinjam (0 bila cukup).
     *
     * @throws AturanBisnisException
     */
    private function periksaSaldo(Karyawan $karyawan, int $tahun, int $hari, JenisPengajuan $jenis): int
    {
        $r = $this->rincianCuti($karyawan, $tahun);
        $sisa = max(0, $r['sisa']);
        if ($hari <= $sisa) {
            return 0;
        }

        // Tambahan luapan ke tahun berikutnya akibat pengajuan ini (memperhitungkan luapan yang sudah ada).
        $pinjam = max(0, $r['terpakai'] + $hari - $r['jatah']) - max(0, $r['terpakai'] - $r['jatah']);
        $sisaDepan = max(0, $this->rincianCuti($karyawan, $tahun + 1)['sisa']);
        if ($pinjam <= $sisaDepan) {
            return $pinjam;
        }

        $depan = $tahun + 1;
        if ($jenis === JenisPengajuan::Cuti) {
            throw new AturanBisnisException(
                "Sisa cuti {$tahun} Anda {$sisa} hari, pengajuan ini {$hari} hari kerja. Kekurangan {$pinjam} hari akan dipotong dari jatah {$depan}, tetapi jatah {$depan} hanya tersisa {$sisaDepan} hari."
            );
        }

        throw new AturanBisnisException(
            "Tanpa surat dan keterangan, {$hari} hari izin/sakit ini memotong cuti tahunan, tetapi saldo tidak cukup (sisa {$sisa} hari, jatah {$depan} tersisa {$sisaDepan} hari). Lampirkan surat (mis. surat dokter) beserta keterangan agar tidak memotong cuti."
        );
    }

    /**
     * Rincian saldo cuti pada $tahun. Luapan = hari yang melebihi jatah tahun sebelumnya (dipinjam dari
     * tahun ini). Hanya pengajuan yang memotong cuti dan berstatus menunggu/disetujui yang dihitung.
     *
     * @return array{jatah: int, sendiri: int, luapan: int, terpakai: int, sisa: int}  sisa dapat negatif pada data lama
     */
    public function rincianCuti(Karyawan $karyawan, int $tahun): array
    {
        $jatah = $karyawan->jatahCutiTahunan();
        $awal = $tahun - self::TAHUN_MUNDUR;

        // Satu query; batas atas "< 1 Jan tahun berikutnya" agar benar baik untuk kolom DATE maupun DATETIME.
        $perTahun = [];
        PengajuanIzin::query()->where('nik', $karyawan->nik)->berlaku()->where('potong_cuti', true)
            ->where('tgl_izin', '>=', $awal.'-01-01')->where('tgl_izin', '<', ($tahun + 1).'-01-01')
            ->get(['tgl_izin', 'jumlah_hari'])
            ->each(function (PengajuanIzin $p) use (&$perTahun) {
                $th = Carbon::parse($p->tgl_izin)->year;
                $perTahun[$th] = ($perTahun[$th] ?? 0) + (int) $p->jumlah_hari;
            });

        // Luapan bergulir dari tahun ke tahun; loop selalu berhenti (maksimal TAHUN_MUNDUR putaran).
        $luapan = 0;
        for ($th = $awal; $th < $tahun; $th++) {
            $luapan = max(0, ($perTahun[$th] ?? 0) + $luapan - $jatah);
        }

        $sendiri = $perTahun[$tahun] ?? 0;
        $terpakai = $sendiri + $luapan;

        return ['jatah' => $jatah, 'sendiri' => $sendiri, 'luapan' => $luapan, 'terpakai' => $terpakai, 'sisa' => $jatah - $terpakai];
    }

    /** Sisa cuti tahun ini yang masih bisa dipakai tanpa meminjam (tidak pernah negatif). */
    public function sisaCuti(Karyawan $karyawan, int $tahun): int
    {
        return max(0, $this->rincianCuti($karyawan, $tahun)['sisa']);
    }

    /** Karyawan membatalkan pengajuannya sendiri (hanya selagi menunggu). */
    public function batal(Karyawan $karyawan, PengajuanIzin $pengajuan): void
    {
        if ($pengajuan->nik !== $karyawan->nik) {
            throw new AturanBisnisException('Pengajuan tidak ditemukan.');
        }
        if ($pengajuan->status_approved !== StatusPengajuan::Menunggu) {
            throw new AturanBisnisException('Hanya pengajuan yang masih menunggu yang dapat dibatalkan.');
        }
        $pengajuan->update(['status_approved' => StatusPengajuan::Dibatalkan->value]);
    }

    /** Direksi menyetujui/menolak pengajuan yang menunggu. */
    public function putuskan(PengajuanIzin $pengajuan, StatusPengajuan $keputusan, User $user, ?string $catatan = null): void
    {
        if (! $user->bolehMemutuskan()) {
            throw new AturanBisnisException('Hanya Direksi yang dapat menyetujui atau menolak pengajuan.');
        }
        if (! in_array($keputusan, [StatusPengajuan::Disetujui, StatusPengajuan::Ditolak], true)) {
            throw new AturanBisnisException('Keputusan tidak valid.');
        }
        if ($pengajuan->status_approved !== StatusPengajuan::Menunggu) {
            throw new AturanBisnisException('Pengajuan ini sudah diputuskan. Kembalikan ke "Menunggu" bila ingin mengubahnya.');
        }

        $pengajuan->update([
            'status_approved' => $keputusan->value,
            'catatan_hr' => $catatan,
            'diputuskan_oleh' => $user->getKey(),
            'diputuskan_pada' => now(),
        ]);
    }

    /** HR/Admin menandai bahwa pengajuan ini sudah diketahui (pemberitahuan, bukan persetujuan). */
    public function tandaiDiketahuiHr(PengajuanIzin $pengajuan, User $user): void
    {
        if (! $user->penerimaInfo()) {
            throw new AturanBisnisException('Hanya HR atau Admin yang dapat menandai pengajuan sebagai diketahui.');
        }
        if ($pengajuan->sudahDiketahuiHr()) {
            return;
        }

        $pengajuan->update(['diketahui_hr_oleh' => $user->getKey(), 'diketahui_hr_pada' => now()]);
    }

    /** Direksi membatalkan keputusan sebelumnya (kembali ke Menunggu). */
    public function kembalikanKeMenunggu(PengajuanIzin $pengajuan): void
    {
        if (! in_array($pengajuan->status_approved, [StatusPengajuan::Disetujui, StatusPengajuan::Ditolak], true)) {
            throw new AturanBisnisException('Hanya pengajuan yang sudah diputuskan yang dapat dikembalikan.');
        }

        $pengajuan->update([
            'status_approved' => StatusPengajuan::Menunggu->value,
            'catatan_hr' => null,
            'diputuskan_oleh' => null,
            'diputuskan_pada' => null,
        ]);
    }
}
