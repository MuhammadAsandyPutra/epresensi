<?php

namespace App\Services;

use App\Enums\JenisPengajuan;
use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\Karyawan;
use App\Models\Pengaturan;
use App\Models\PengajuanIzin;
use App\Models\Presensi;
use App\Models\PresensiTahan;
use App\Support\Geo;
use App\Support\Waktu;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Seluruh aturan presensi masuk/pulang ada di sini (bukan di controller).
 * SEMUA validasi dilakukan di server: lokasi, radius, jam, dan waktu memakai jam server.
 */
class PresensiService
{
    public function __construct(private JadwalService $jadwal) {}

    /**
     * Keadaan presensi hari ini (untuk halaman karyawan): baris presensi yang sudah tercatat dan/atau
     * presensi luar kantor yang sedang DITAHAN. Jam masuk/pulang efektif = yang tercatat, bila tidak ada
     * maka yang ditahan.
     *
     * @return array{presensi: ?Presensi, tahan: ?PresensiTahan, jamIn: ?string, jamOut: ?string, sudahMasuk: bool, selesai: bool}
     */
    public function sesiHariIni(Karyawan $karyawan, string $tanggal): array
    {
        $presensi = Presensi::query()->where('nik', $karyawan->nik)->where('tanggal_presensi', $tanggal)->first();
        $tahan = PresensiTahan::query()->menunggu()->where('nik', $karyawan->nik)->where('tanggal_presensi', $tanggal)->first();
        $jamIn = $presensi?->jam_in ?? $tahan?->jam_in;
        $jamOut = $presensi?->jam_out ?? $tahan?->jam_out;

        return ['presensi' => $presensi, 'tahan' => $tahan, 'jamIn' => $jamIn, 'jamOut' => $jamOut, 'sudahMasuk' => $jamIn !== null, 'selesai' => $jamOut !== null];
    }

    /**
     * Catat presensi. Otomatis menentukan masuk atau pulang.
     *
     * Saat tugas luar yang DISETUJUI berlaku pada hari ini, radius kantor tidak dicek (selfie, GPS,
     * dan jam tetap divalidasi). Pulang melewati jam pulang + batas lembur wajib disertai keterangan lembur;
     * pulang SEBELUM jam pulang wajib disertai alasan (menunggu keputusan Direksi, tetap tercatat).
     *
     * Karyawan yang ditandai HR (`boleh_presensi_luar`) boleh presensi di luar radius: presensinya tidak
     * ditolak melainkan DITAHAN (tabel presensi_tahan) dengan tujuan wajib diisi, dan baru tercatat di
     * presensi (dengan jam asli) setelah Direksi menyetujui. Karyawan lain tetap ditolak di luar radius.
     *
     * @return array{jenis: string, pesan: string, terlambat_menit: int, lembur_menit: int, pulang_cepat_menit: int, ditahan?: bool}
     *
     * @throws AturanBisnisException bila ada aturan yang dilanggar
     */
    public function catat(Karyawan $karyawan, string $lokasi, ?float $akurasi, string $fotoDataUrl, ?string $ip = null, ?string $keteranganLembur = null, ?string $alasanPulangCepat = null, ?string $alasanLuarKantor = null): array
    {
        $sekarang = now();
        $tanggal = $sekarang->toDateString();
        $jam = $sekarang->format('H:i:s');

        $posisi = Geo::parse($lokasi)
            ?? throw new AturanBisnisException('Lokasi tidak terbaca. Aktifkan GPS lalu coba lagi.', 'lokasi');

        $tugasLuar = $this->tugasLuarBerlaku($karyawan, $tanggal);
        $bolehLuar = (bool) $karyawan->boleh_presensi_luar;

        $cabang = $karyawan->cabang;
        $kantor = $cabang?->koordinat();
        if (! $tugasLuar && ! $bolehLuar && (! $cabang || ! $kantor)) {
            throw new AturanBisnisException('Lokasi kantor Anda belum diatur. Hubungi HR.', 'kantor');
        }

        $maksAkurasi = (float) Pengaturan::ambil('gps.akurasi_maks');
        if ($akurasi !== null && $akurasi > $maksAkurasi) {
            throw new AturanBisnisException(
                'Akurasi GPS rendah (' . round($akurasi) . ' m). Pindah ke area terbuka lalu coba lagi.',
                'akurasi'
            );
        }

        $jarak = $kantor ? Geo::jarak($posisi[0], $posisi[1], $kantor[0], $kantor[1]) : null;

        // "Di luar" = di luar radius kantor tanpa tugas luar yang disetujui. Karyawan yang tidak ditandai
        // HR ditolak; yang ditandai diterima tetapi DITAHAN sampai Direksi menyetujui.
        $luar = ! $tugasLuar && ($jarak === null || $jarak > (int) $cabang?->radius_cabang);
        if ($luar && ! $bolehLuar) {
            throw new AturanBisnisException(
                "Anda berada {$jarak} m dari kantor, radius maksimal {$cabang->radius_cabang} m.",
                'radius'
            );
        }

        $this->pastikanTidakSedangIzin($karyawan, $tanggal);

        $jamKerja = $this->jadwal->untukTanggal($karyawan, $sekarang)
            ?? throw new AturanBisnisException('Hari ini bukan hari kerja Anda.', 'jadwal');

        $sesi = $this->sesiHariIni($karyawan, $tanggal);
        /** @var Presensi|null $presensi */
        $presensi = $sesi['presensi'];
        /** @var PresensiTahan|null $tahan */
        $tahan = $sesi['tahan'];

        if (! $sesi['sudahMasuk']) {
            if (Waktu::detik($jam) < Waktu::detik($jamKerja->awal_jam_masuk)) {
                throw new AturanBisnisException('Belum waktunya presensi masuk (mulai ' . Waktu::hm($jamKerja->awal_jam_masuk) . ').', 'waktu');
            }
            if (Waktu::detik($jam) > Waktu::detik($jamKerja->akhir_jam_masuk)) {
                throw new AturanBisnisException('Waktu presensi masuk sudah ditutup (batas ' . Waktu::hm($jamKerja->akhir_jam_masuk) . '). Hubungi HR.', 'waktu');
            }

            // Tujuan dicek SEBELUM foto disimpan: tanpa tujuan tidak ada berkas yang tertinggal.
            $tujuan = $luar ? $this->wajibTujuanLuar($alasanLuarKantor, $jarak) : null;

            $foto = $this->simpanFoto($karyawan, $tanggal, 'in', $fotoDataUrl);

            try {
                if ($luar) {
                    PresensiTahan::query()->create([
                        'nik' => $karyawan->nik,
                        'tanggal_presensi' => $tanggal,
                        'kode_jam_kerja' => $jamKerja->kode_jam_kerja,
                        'jam_in' => $jam,
                        'foto_in' => $foto,
                        'lokasi_in' => $lokasi,
                        'jarak_in' => $jarak,
                        'akurasi_in' => $akurasi !== null ? (int) round($akurasi) : null,
                        'alasan' => $tujuan,
                        'ip_address' => $ip,
                        'status' => StatusPengajuan::Menunggu->value,
                    ]);
                } else {
                    Presensi::query()->create([
                        'nik' => $karyawan->nik,
                        'tanggal_presensi' => $tanggal,
                        'jam_in' => $jam,
                        'foto_in' => $foto,
                        'lokasi_in' => $lokasi,
                        'kode_jam_kerja' => $jamKerja->kode_jam_kerja,
                        'jarak_in' => $jarak,
                        'akurasi_in' => $akurasi !== null ? (int) round($akurasi) : null,
                        // Saat tugas luar, koordinat yang sama berulang itu wajar (satu lokasi tugas).
                        'perlu_review' => $this->mencurigakan($karyawan, $lokasi, $akurasi, ! $tugasLuar),
                        'ip_address' => $ip,
                        'tugas_luar' => $tugasLuar !== null,
                    ]);
                }
            } catch (UniqueConstraintViolationException) {
                Storage::disk(config('presensi.foto.disk'))->delete($foto);
                throw new AturanBisnisException('Presensi masuk hari ini sudah tercatat.', 'selesai');
            } catch (Throwable $e) {
                Storage::disk(config('presensi.foto.disk'))->delete($foto);
                throw $e;
            }

            if ($luar) {
                return [
                    'jenis' => 'masuk',
                    'ditahan' => true,
                    'pesan' => 'Presensi masuk dari luar kantor diterima dan DITAHAN sampai Direksi menyetujui. Jam presensi Anda (' . Waktu::hm($jam) . ') yang akan dipakai.',
                    'terlambat_menit' => 0,
                    'lembur_menit' => 0,
                    'pulang_cepat_menit' => 0,
                ];
            }

            $menit = $jamKerja->menitTerlambat($jam);

            return [
                'jenis' => 'masuk',
                'pesan' => $menit > 0 ? "Presensi masuk tercatat. Anda terlambat {$menit} menit." : 'Terima kasih, selamat bekerja!',
                'terlambat_menit' => $menit,
                'lembur_menit' => 0,
                'pulang_cepat_menit' => 0,
            ];
        }

        if (! $sesi['selesai']) {
            // Pulang ikut ditahan bila di luar radius ATAU presensi masuk hari ini sedang ditahan
            // (agar keduanya diputuskan bersama oleh Direksi).
            $ditahan = $luar || $tahan !== null;
            $tujuan = ($luar && $tahan === null) ? $this->wajibTujuanLuar($alasanLuarKantor, $jarak) : null;

            // Pulang sebelum jam pulang tetap boleh (keperluan mendadak), tetapi alasan WAJIB dan
            // keputusannya menyusul di Direksi. Dicek SEBELUM foto disimpan: tanpa alasan tidak ada berkas tertinggal.
            $detikSekarang = Waktu::detik($jam);
            $detikPulang = Waktu::detik($jamKerja->jam_pulang);
            $cepat = $detikSekarang < $detikPulang ? max(1, (int) ceil(($detikPulang - $detikSekarang) / 60)) : 0;
            $alasan = trim((string) $alasanPulangCepat);
            if ($cepat > 0) {
                if ($alasan === '') {
                    throw new AturanBisnisException("Anda pulang {$cepat} menit sebelum jam pulang (".Waktu::hm($jamKerja->jam_pulang).'). Isi alasan pulang lebih awal untuk melanjutkan.', 'pulang_cepat');
                }
                if (mb_strlen($alasan) < 5) {
                    throw new AturanBisnisException('Alasan pulang lebih awal terlalu singkat. Jelaskan keperluan Anda.', 'pulang_cepat');
                }
            }

            // Lembur hanya relevan bila pulang SETELAH jam pulang.
            $lembur = $cepat > 0 ? 0 : $this->menitLembur($jamKerja->jam_pulang, $jam);
            $keterangan = trim((string) $keteranganLembur);
            if ($lembur > 0) {
                if ($keterangan === '') {
                    throw new AturanBisnisException("Anda pulang {$lembur} menit setelah jam kerja. Isi keterangan lembur untuk melanjutkan.", 'lembur');
                }
                if (mb_strlen($keterangan) < 5) {
                    throw new AturanBisnisException('Keterangan lembur terlalu singkat. Jelaskan pekerjaan yang dilakukan.', 'lembur');
                }
            }

            $foto = $this->simpanFoto($karyawan, $tanggal, 'out', $fotoDataUrl);
            $ubah = [
                'jam_out' => $jam,
                'foto_out' => $foto,
                'lokasi_out' => $lokasi,
                'jarak_out' => $jarak,
            ];

            if ($ditahan) {
                // Info lembur/pulang awal ikut disimpan di baris tertahan; diputuskan bersama saat Direksi menyetujui.
                if ($lembur > 0) {
                    $ubah += ['lembur_menit' => $lembur, 'keterangan_lembur' => mb_substr($keterangan, 0, 500)];
                }
                if ($cepat > 0) {
                    $ubah += ['pulang_cepat_menit' => $cepat, 'alasan_pulang_cepat' => mb_substr($alasan, 0, 500)];
                }

                if ($tahan !== null) {
                    $tahan->update($ubah);
                } else {
                    PresensiTahan::query()->create($ubah + [
                        'nik' => $karyawan->nik,
                        'tanggal_presensi' => $tanggal,
                        'kode_jam_kerja' => $jamKerja->kode_jam_kerja,
                        'alasan' => $tujuan,
                        'ip_address' => $ip,
                        'status' => StatusPengajuan::Menunggu->value,
                    ]);
                }

                return [
                    'jenis' => 'pulang',
                    'ditahan' => true,
                    'pesan' => 'Presensi pulang dari luar kantor diterima dan DITAHAN sampai Direksi menyetujui. Jam presensi Anda (' . Waktu::hm($jam) . ') yang akan dipakai.',
                    'terlambat_menit' => 0,
                    'lembur_menit' => $lembur,
                    'pulang_cepat_menit' => $cepat,
                ];
            }

            if ($lembur > 0) {
                $ubah += [
                    'lembur_menit' => $lembur,
                    'keterangan_lembur' => mb_substr($keterangan, 0, 500),
                    'lembur_status' => StatusPengajuan::Menunggu->value, // menunggu persetujuan Direksi
                ];
            }
            if ($cepat > 0) {
                $ubah += [
                    'pulang_cepat_menit' => $cepat,
                    'alasan_pulang_cepat' => mb_substr($alasan, 0, 500),
                    'pulang_cepat_status' => StatusPengajuan::Menunggu->value, // menunggu keputusan Direksi
                ];
            }
            $presensi->update($ubah);

            return [
                'jenis' => 'pulang',
                'pesan' => match (true) {
                    $cepat > 0 => "Presensi pulang tercatat. Pulang lebih awal {$cepat} menit dikirim ke Direksi untuk persetujuan.",
                    $lembur > 0 => "Presensi pulang tercatat. Lembur {$lembur} menit dikirim ke Direksi untuk persetujuan.",
                    default => 'Presensi pulang tercatat. Hati-hati di jalan!',
                },
                'terlambat_menit' => 0,
                'lembur_menit' => $lembur,
                'pulang_cepat_menit' => $cepat,
            ];
        }

        throw new AturanBisnisException('Anda sudah menyelesaikan presensi masuk dan pulang hari ini.', 'selesai');
    }

    /** Tujuan di luar kantor wajib diisi (mis. "menagih angsuran di Pasar X") sebelum presensi ditahan. */
    private function wajibTujuanLuar(?string $tujuan, ?int $jarak): string
    {
        $tujuan = trim((string) $tujuan);
        if ($tujuan === '') {
            $posisi = $jarak !== null ? "Anda berada {$jarak} m dari kantor." : 'Lokasi kantor belum diatur.';
            throw new AturanBisnisException($posisi.' Presensi dari luar kantor akan DITAHAN sampai disetujui Direksi. Isi tujuan Anda (mis. menagih angsuran di Pasar X).', 'luar_kantor');
        }
        if (mb_strlen($tujuan) < 5) {
            throw new AturanBisnisException('Tujuan terlalu singkat. Jelaskan keperluan Anda di luar kantor.', 'luar_kantor');
        }

        return mb_substr($tujuan, 0, 500);
    }

    private function pastikanTidakSedangIzin(Karyawan $karyawan, string $tanggal): void
    {
        $pengajuan = PengajuanIzin::query()
            ->where('nik', $karyawan->nik)
            ->where('status_approved', StatusPengajuan::Disetujui->value)
            ->where('status', '!=', JenisPengajuan::TugasLuar->value) // tugas luar tetap wajib presensi
            ->berimpitDengan($tanggal, $tanggal)
            ->first();

        if ($pengajuan) {
            throw new AturanBisnisException(
                'Anda memiliki ' . strtolower($pengajuan->jenis()->label()) . ' yang disetujui pada hari ini, presensi tidak diperlukan.',
                'izin'
            );
        }
    }

    /** Tugas luar yang sudah DISETUJUI Direksi dan mencakup tanggal ini (null bila tidak ada). */
    private function tugasLuarBerlaku(Karyawan $karyawan, string $tanggal): ?PengajuanIzin
    {
        return PengajuanIzin::query()
            ->where('nik', $karyawan->nik)
            ->where('status_approved', StatusPengajuan::Disetujui->value)
            ->where('status', JenisPengajuan::TugasLuar->value)
            ->berimpitDengan($tanggal, $tanggal)
            ->first();
    }

    /** Menit lembur = selisih jam pulang sebenarnya dan jam pulang jadwal; 0 bila di bawah batas minimal. */
    private function menitLembur(string $jamPulangJadwal, string $jamSekarang): int
    {
        $menit = intdiv(Waktu::detik($jamSekarang) - Waktu::detik($jamPulangJadwal), 60);
        $minimal = max(1, (int) Pengaturan::ambil('lembur.minimal_menit'));

        return $menit >= $minimal ? $menit : 0;
    }

    /**
     * Tandai untuk direview HR (BUKAN ditolak): akurasi GPS rendah, atau koordinat
     * persis sama dengan 3 presensi terakhir (indikasi lokasi palsu/tetap).
     */
    private function mencurigakan(Karyawan $karyawan, string $lokasi, ?float $akurasi, bool $cekKoordinatSama = true): bool
    {
        if ($akurasi !== null && $akurasi > (float) Pengaturan::ambil('gps.review_akurasi')) {
            return true;
        }
        if (! $cekKoordinatSama) {
            return false;
        }

        $terakhir = Presensi::query()->where('nik', $karyawan->nik)
            ->orderByDesc('tanggal_presensi')->limit(3)->pluck('lokasi_in');

        return $terakhir->count() === 3 && $terakhir->every(fn($l) => $l === $lokasi);
    }

    /** Validasi & simpan foto (data URL base64) ke disk PRIVAT; mengembalikan path relatif. */
    private function simpanFoto(Karyawan $karyawan, string $tanggal, string $jenis, string $dataUrl): string
    {
        if (! preg_match('#^data:image/(png|jpeg|jpg);base64,#', $dataUrl, $cocok)) {
            throw new AturanBisnisException('Foto tidak valid. Ambil ulang foto selfie.', 'foto');
        }

        $biner = base64_decode(substr($dataUrl, strlen($cocok[0])), true);
        if ($biner === false || strlen($biner) < 1000) {
            throw new AturanBisnisException('Foto tidak valid. Ambil ulang foto selfie.', 'foto');
        }
        if (strlen($biner) > config('presensi.foto.maks_kb') * 1024) {
            throw new AturanBisnisException('Ukuran foto terlalu besar. Ambil ulang foto.', 'foto');
        }
        if (@getimagesizefromstring($biner) === false) {
            throw new AturanBisnisException('Berkas foto rusak. Ambil ulang foto selfie.', 'foto');
        }

        $ekstensi = $cocok[1] === 'png' ? 'png' : 'jpg';
        $nik = preg_replace('/[^A-Za-z0-9_-]/', '_', $karyawan->nik);
        $path = 'presensi/' . substr($tanggal, 0, 4) . '/' . substr($tanggal, 5, 2)
            . '/' . $nik . '_' . str_replace('-', '', $tanggal) . '_' . $jenis . '.' . $ekstensi;

        if (! Storage::disk(config('presensi.foto.disk'))->put($path, $biner)) {
            Log::error('Gagal menyimpan foto presensi', ['path' => $path]);
            throw new AturanBisnisException('Foto gagal disimpan di server. Hubungi admin.', 'foto');
        }

        return $path;
    }
}
