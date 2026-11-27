<?php

namespace Tests\Feature;

use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\HariLibur;
use App\Models\PengajuanIzin;
use App\Services\PengajuanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MembuatData;
use Tests\TestCase;

class PengajuanTest extends TestCase
{
    use MembuatData, RefreshDatabase;

    private PengajuanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('presensi.foto.disk')); // lampiran surat tidak boleh menulis ke penyimpanan sungguhan
        Carbon::setTestNow('2026-11-16 08:00:00'); // Senin
        $this->siapkanMaster();
        $this->service = app(PengajuanService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $o = []): array
    {
        return array_merge(['jenis' => 3, 'tgl_izin' => '2026-11-23', 'tgl_selesai' => '2026-11-25', 'keterangan' => 'Acara keluarga'], $o);
    }

    public function test_hari_kerja_mengecualikan_akhir_pekan_dan_libur(): void
    {
        HariLibur::query()->create(['tanggal' => '2026-11-18', 'keterangan' => 'Libur uji']);
        $k = $this->buatKaryawan();

        // Sen 16 - Jum 20 (5 hari) dikurangi 1 libur = 4
        $this->assertSame(4, $this->service->hitungHariKerja($k, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-20')));
        // Sen 16 - Sen 23 termasuk sabtu-minggu: 6 hari kerja - 1 libur = 5
        $this->assertSame(5, $this->service->hitungHariKerja($k, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-23')));
    }

    public function test_cuti_tersimpan_menunggu_dan_mengurangi_sisa(): void
    {
        $k = $this->buatKaryawan();
        $p = $this->service->ajukan($k, $this->data());

        $this->assertSame(StatusPengajuan::Menunggu, $p->status_approved);
        $this->assertSame(3, $p->jumlah_hari);
        $this->assertSame(9, $this->service->sisaCuti($k, 2026));
    }

    public function test_tanggal_bentrok_ditolak(): void
    {
        $k = $this->buatKaryawan();
        $this->service->ajukan($k, $this->data());

        $this->expectException(AturanBisnisException::class);
        $this->service->ajukan($k, $this->data(['jenis' => 1, 'tgl_izin' => '2026-11-24', 'tgl_selesai' => '2026-11-24']));
    }

    private function surat(): UploadedFile
    {
        return UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf');
    }

    // ------------------------------------------------------------ aturan izin/sakit & saldo cuti

    public function test_izin_sakit_tanpa_kuota_bulanan_bila_berdokumen_tidak_memotong_cuti(): void
    {
        $k = $this->buatKaryawan();

        // Empat izin berdokumen dalam satu bulan: tidak ada kuota, tidak ada potongan.
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'] as $tgl) {
            $p = $this->service->ajukan($k, $this->data(['jenis' => 1, 'tgl_izin' => $tgl, 'tgl_selesai' => $tgl, 'keterangan' => 'Demam tinggi']), $this->surat());
            $this->assertFalse($p->potong_cuti);
            $this->assertSame(0, $p->hari_pinjam);
        }

        $this->assertSame(12, $this->service->sisaCuti($k, 2026));
    }

    public function test_izin_tanpa_surat_atau_tanpa_keterangan_memotong_cuti(): void
    {
        $k = $this->buatKaryawan();

        $a = $this->service->ajukan($k, $this->data(['jenis' => 1, 'tgl_izin' => '2026-11-17', 'tgl_selesai' => '2026-11-17', 'keterangan' => 'Urusan keluarga'])); // tanpa surat
        $b = $this->service->ajukan($k, $this->data(['jenis' => 2, 'tgl_izin' => '2026-11-18', 'tgl_selesai' => '2026-11-18', 'keterangan' => '']), $this->surat());      // surat tanpa keterangan
        $c = $this->service->ajukan($k, $this->data(['jenis' => 2, 'tgl_izin' => '2026-11-19', 'tgl_selesai' => '2026-11-19', 'keterangan' => null]));                    // tanpa keduanya

        foreach ([$a, $b, $c] as $p) {
            $this->assertTrue($p->potong_cuti);
        }
        $this->assertNull($c->keterangan);
        $this->assertSame(9, $this->service->sisaCuti($k, 2026)); // 12 - 3 hari
    }

    public function test_ditolak_atau_dibatalkan_mengembalikan_saldo(): void
    {
        $k = $this->buatKaryawan();
        $p = $this->service->ajukan($k, $this->data());
        $this->assertSame(9, $this->service->sisaCuti($k, 2026));

        $this->service->putuskan($p, StatusPengajuan::Ditolak, $this->buatAdmin('direksi'), 'Bentrok jadwal');
        $this->assertSame(12, $this->service->sisaCuti($k, 2026));
    }

    public function test_kekurangan_cuti_dipinjam_dari_jatah_tahun_depan(): void
    {
        $k = $this->buatKaryawan(); // jatah 12

        // Pakai 10 hari kerja (23 Nov - 4 Des) -> sisa 2
        $this->service->ajukan($k, $this->data(['tgl_izin' => '2026-11-23', 'tgl_selesai' => '2026-12-04']));
        $this->assertSame(2, $this->service->sisaCuti($k, 2026));

        // Ingin cuti 4 hari (7-10 Des): 2 hari dari sisa, 2 hari dipinjam dari jatah 2027
        $p = $this->service->ajukan($k, $this->data(['tgl_izin' => '2026-12-07', 'tgl_selesai' => '2026-12-10']));

        $this->assertSame(4, $p->jumlah_hari);
        $this->assertSame(2, $p->hari_pinjam);
        $this->assertSame(0, $this->service->sisaCuti($k, 2026));

        $depan = $this->service->rincianCuti($k, 2027);
        $this->assertSame(2, $depan['luapan']);
        $this->assertSame(10, $depan['sisa']); // 12 - 2 pinjaman
    }

    public function test_pinjaman_dibatasi_sisa_jatah_tahun_depan(): void
    {
        $k = $this->buatKaryawan('1001', ['jatah_cuti' => 2]);

        // 4 hari: sisa 2 + pinjam 2 (jatah 2027 = 2) -> tepat cukup
        $this->service->ajukan($k, $this->data(['tgl_izin' => '2026-11-23', 'tgl_selesai' => '2026-11-26']));
        $this->assertSame(0, $this->service->rincianCuti($k, 2027)['sisa']);

        // Tambahan 1 hari lagi: jatah 2027 sudah habis dipinjam -> ditolak
        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('jatah 2027');
        $this->service->ajukan($k, $this->data(['tgl_izin' => '2026-11-30', 'tgl_selesai' => '2026-11-30']));
    }

    public function test_cuti_melebihi_sisa_dan_jatah_tahun_depan_ditolak(): void
    {
        $k = $this->buatKaryawan('1001', ['jatah_cuti' => 2]);

        $this->expectException(AturanBisnisException::class);
        $this->service->ajukan($k, $this->data(['tgl_izin' => '2026-11-23', 'tgl_selesai' => '2026-11-27'])); // 5 hari: pinjam 3 > 2
    }

    public function test_izin_tanpa_surat_ditolak_bila_saldo_tidak_cukup_dan_menyarankan_surat(): void
    {
        $k = $this->buatKaryawan('1001', ['jatah_cuti' => 1]);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('Lampirkan surat');
        $this->service->ajukan($k, $this->data(['jenis' => 2, 'tgl_izin' => '2026-11-17', 'tgl_selesai' => '2026-11-19', 'keterangan' => 'Sakit'])); // 3 hari tanpa surat
    }

    public function test_pengajuan_yang_memotong_cuti_tidak_boleh_lintas_tahun_tetapi_berdokumen_boleh(): void
    {
        $k = $this->buatKaryawan();

        try {
            $this->service->ajukan($k, $this->data(['tgl_izin' => '2026-12-30', 'tgl_selesai' => '2027-01-04']));
            $this->fail('Cuti lintas tahun seharusnya ditolak.');
        } catch (AturanBisnisException) {
            $this->assertDatabaseCount('pengajuan_izin', 0);
        }

        $p = $this->service->ajukan($k, $this->data(['jenis' => 2, 'tgl_izin' => '2026-12-30', 'tgl_selesai' => '2027-01-04', 'keterangan' => 'Rawat inap']), $this->surat());
        $this->assertFalse($p->potong_cuti);
    }

    public function test_direksi_menyetujui_dan_bisa_membatalkan_keputusan(): void
    {
        $k = $this->buatKaryawan();
        $direksi = $this->buatAdmin('direksi');
        $p = $this->service->ajukan($k, $this->data());

        $this->service->putuskan($p, StatusPengajuan::Disetujui, $direksi, 'OK');
        $this->assertSame(StatusPengajuan::Disetujui, $p->fresh()->status_approved);

        $this->service->kembalikanKeMenunggu($p->fresh());
        $this->assertSame(StatusPengajuan::Menunggu, $p->fresh()->status_approved);
    }

    public function test_hr_tidak_boleh_memutuskan_hanya_menandai_diketahui(): void
    {
        $k = $this->buatKaryawan();
        $hr = $this->buatAdmin('hr');
        $p = $this->service->ajukan($k, $this->data());

        try {
            $this->service->putuskan($p, StatusPengajuan::Disetujui, $hr);
            $this->fail('HR seharusnya tidak boleh memutuskan.');
        } catch (AturanBisnisException) {
            $this->assertSame(StatusPengajuan::Menunggu, $p->fresh()->status_approved);
        }

        $this->assertFalse($p->fresh()->sudahDiketahuiHr());
        $this->service->tandaiDiketahuiHr($p, $hr);
        $this->assertTrue($p->fresh()->sudahDiketahuiHr());
        $this->assertSame($hr->id, (int) $p->fresh()->diketahui_hr_oleh);
    }

    public function test_tugas_luar_wajib_lokasi_dan_tidak_memotong_cuti(): void
    {
        $k = $this->buatKaryawan();

        try {
            $this->service->ajukan($k, $this->data(['jenis' => 4, 'tgl_izin' => '2026-11-17', 'tgl_selesai' => '2026-11-17']));
            $this->fail('Lokasi tugas wajib diisi.');
        } catch (AturanBisnisException) {
            $this->assertDatabaseCount('pengajuan_izin', 0);
        }

        // Empat tugas luar dalam sebulan: tidak memotong saldo cuti.
        foreach (['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'] as $tgl) {
            $p = $this->service->ajukan($k, $this->data(['jenis' => 4, 'tgl_izin' => $tgl, 'tgl_selesai' => $tgl, 'lokasi_tugas' => 'Cabang Banjarmasin']));
            $this->assertSame('Cabang Banjarmasin', $p->lokasi_tugas);
        }
        $this->assertSame(12, $this->service->sisaCuti($k, 2026));
    }

    public function test_karyawan_hanya_bisa_membatalkan_miliknya(): void
    {
        $a = $this->buatKaryawan('1001');
        $b = $this->buatKaryawan('1002');
        $p = $this->service->ajukan($a, $this->data());

        $this->expectException(AturanBisnisException::class);
        $this->service->batal($b, $p);
    }

    public function test_pengajuan_milik_orang_lain_tidak_bisa_dibatalkan_lewat_http(): void
    {
        $a = $this->buatKaryawan('1001');
        $b = $this->buatKaryawan('1002');
        $p = $this->service->ajukan($a, $this->data());

        $this->actingAs($b, 'karyawan')->post(route('izin.batal', $p))->assertSessionHas('galat');
        $this->assertSame(StatusPengajuan::Menunggu, PengajuanIzin::query()->find($p->id)->status_approved);
    }
}
