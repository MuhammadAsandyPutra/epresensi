<?php

namespace Tests\Feature;

use App\Enums\StatusPengajuan;
use App\Models\PengajuanIzin;
use App\Models\Presensi;
use App\Models\User;
use App\Services\PengajuanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MembuatData;
use Tests\TestCase;

class DireksiTugasLuarLemburTest extends TestCase
{
    use MembuatData, RefreshDatabase;

    private const DI_KANTOR = '-3.388050,114.635020';

    private const DI_LUAR = '-3.390500,114.635000'; // ± 278 m dari kantor

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-16 08:00:00'); // Senin
        $this->siapkanMaster();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kirim(string $lokasi, array $lebih = [])
    {
        return $this->postJson(route('presensi.store'), array_merge(['lokasi' => $lokasi, 'akurasi' => 10, 'foto' => $this->fotoDataUrl()], $lebih));
    }

    private function tugasLuarDisetujui(string $nik = '1001'): PengajuanIzin
    {
        $k = \App\Models\Karyawan::query()->findOrFail($nik);
        $p = app(PengajuanService::class)->ajukan($k, [
            'jenis' => 4, 'tgl_izin' => '2026-11-16', 'tgl_selesai' => '2026-11-16',
            'keterangan' => 'Kunjungan nasabah', 'lokasi_tugas' => 'Cabang Banjarmasin',
        ]);
        app(PengajuanService::class)->putuskan($p, StatusPengajuan::Disetujui, $this->buatAdmin(User::ROLE_DIREKSI));

        return $p;
    }

    // ------------------------------------------------------------ peran & persetujuan

    public function test_hanya_direksi_yang_bisa_memutuskan_lewat_http(): void
    {
        $k = $this->buatKaryawan();
        $p = app(PengajuanService::class)->ajukan($k, ['jenis' => 3, 'tgl_izin' => '2026-11-23', 'tgl_selesai' => '2026-11-23', 'keterangan' => 'Acara keluarga']);

        $this->actingAs($this->buatAdmin(User::ROLE_HR), 'user')
            ->post(route('panel.pengajuan.putuskan', $p), ['keputusan' => 'setuju'])->assertForbidden();
        $this->assertSame(StatusPengajuan::Menunggu, $p->fresh()->status_approved);

        $this->actingAs($this->buatAdmin(User::ROLE_DIREKSI), 'user')
            ->post(route('panel.pengajuan.putuskan', $p), ['keputusan' => 'setuju'])->assertSessionHas('sukses');
        $this->assertSame(StatusPengajuan::Disetujui, $p->fresh()->status_approved);
    }

    public function test_hr_dan_direksi_bisa_melihat_daftar_tetapi_direksi_tidak_membuka_data_master(): void
    {
        $hr = $this->buatAdmin(User::ROLE_HR);
        $this->actingAs($hr, 'user')->get(route('panel.pengajuan.index'))->assertOk();
        $this->actingAs($hr, 'user')->get(route('panel.lembur.index'))->assertOk();

        $direksi = $this->buatAdmin(User::ROLE_DIREKSI);
        $this->actingAs($direksi, 'user')->get(route('panel.dashboard'))->assertOk();
        $this->actingAs($direksi, 'user')->get(route('panel.pengajuan.index'))->assertOk();
        $this->actingAs($direksi, 'user')->get(route('panel.lembur.index'))->assertOk();
        $this->actingAs($direksi, 'user')->get(route('panel.karyawan.index'))->assertForbidden();
        $this->actingAs($direksi, 'user')->get(route('panel.pengguna.index'))->assertForbidden();
    }

    public function test_admin_dapat_mengelola_akun_panel(): void
    {
        $admin = $this->buatAdmin(User::ROLE_ADMIN);

        $this->actingAs($admin, 'user')->post(route('panel.pengguna.store'), [
            'name' => 'Pak Direktur', 'email' => 'direktur@uji.test', 'role' => 'direksi', 'password' => 'rahasia123',
        ])->assertRedirect(route('panel.pengguna.index'));
        $this->assertSame('direksi', User::query()->where('email', 'direktur@uji.test')->value('role'));

        // Admin terakhir tidak boleh dihapus
        $this->actingAs($admin, 'user')->delete(route('panel.pengguna.destroy', $admin))->assertSessionHas('galat');
        $this->assertDatabaseHas('users', ['email' => $admin->email]);
    }

    // ------------------------------------------------------------ tugas luar

    public function test_tugas_luar_disetujui_boleh_presensi_di_luar_radius(): void
    {
        $this->buatKaryawan();
        $this->tugasLuarDisetujui();
        $this->actingAs(\App\Models\Karyawan::query()->find('1001'), 'karyawan');

        Carbon::setTestNow('2026-11-16 08:30:00');
        $this->kirim(self::DI_LUAR)->assertOk()->assertJson(['ok' => true, 'jenis' => 'masuk']);

        $p = Presensi::query()->where('nik', '1001')->firstOrFail();
        $this->assertTrue($p->tugas_luar);
    }

    public function test_tanpa_tugas_luar_di_luar_radius_tetap_ditolak(): void
    {
        $this->actingAs($this->buatKaryawan(), 'karyawan');
        Carbon::setTestNow('2026-11-16 08:30:00');

        $this->kirim(self::DI_LUAR)->assertStatus(422)->assertJson(['kode' => 'radius']);
    }

    public function test_tugas_luar_yang_belum_disetujui_tidak_melewati_radius(): void
    {
        $k = $this->buatKaryawan();
        app(PengajuanService::class)->ajukan($k, [
            'jenis' => 4, 'tgl_izin' => '2026-11-16', 'tgl_selesai' => '2026-11-16',
            'keterangan' => 'Kunjungan nasabah', 'lokasi_tugas' => 'Cabang Banjarmasin',
        ]);
        $this->actingAs($k, 'karyawan');
        Carbon::setTestNow('2026-11-16 08:30:00');

        $this->kirim(self::DI_LUAR)->assertStatus(422)->assertJson(['kode' => 'radius']);
    }

    public function test_tugas_luar_tetap_wajib_presensi_bukan_dibebaskan(): void
    {
        $this->buatKaryawan();
        $this->tugasLuarDisetujui();
        $this->actingAs(\App\Models\Karyawan::query()->find('1001'), 'karyawan');

        // Presensi tidak diblokir dengan alasan "memiliki izin yang disetujui".
        Carbon::setTestNow('2026-11-16 08:30:00');
        $this->kirim(self::DI_LUAR)->assertOk();
    }

    // ------------------------------------------------------------ lembur

    private function masukLalu(string $jamPulang): void
    {
        $this->actingAs($this->buatKaryawan(), 'karyawan');
        Carbon::setTestNow('2026-11-16 08:50:00');
        $this->kirim(self::DI_KANTOR)->assertOk();
        Carbon::setTestNow($jamPulang);
    }

    public function test_pulang_sedikit_setelah_jam_kerja_bukan_lembur(): void
    {
        $this->masukLalu('2026-11-16 17:10:00'); // 10 menit < batas 30 menit

        $this->kirim(self::DI_KANTOR)->assertOk()->assertJson(['jenis' => 'pulang', 'lembur_menit' => 0]);
        $this->assertNull(Presensi::query()->first()->lembur_status);
    }

    public function test_pulang_lembur_wajib_keterangan(): void
    {
        $this->masukLalu('2026-11-16 18:30:00'); // 90 menit

        $this->kirim(self::DI_KANTOR)->assertStatus(422)->assertJson(['kode' => 'lembur']);
        $this->assertNull(Presensi::query()->first()->jam_out); // belum tercatat

        $this->kirim(self::DI_KANTOR, ['keterangan_lembur' => 'x'])->assertStatus(422)->assertJson(['kode' => 'lembur']);
    }

    public function test_pulang_lembur_dengan_keterangan_menunggu_persetujuan_direksi(): void
    {
        $this->masukLalu('2026-11-16 18:30:00');

        $this->kirim(self::DI_KANTOR, ['keterangan_lembur' => 'Menyelesaikan laporan akhir bulan'])
            ->assertOk()->assertJson(['jenis' => 'pulang', 'lembur_menit' => 90]);

        $p = Presensi::query()->firstOrFail();
        $this->assertSame(90, (int) $p->lembur_menit);
        $this->assertSame(StatusPengajuan::Menunggu, $p->lembur_status);
        $this->assertSame('Menyelesaikan laporan akhir bulan', $p->keterangan_lembur);

        // HR tidak bisa memutuskan, hanya mengetahui; Direksi memutuskan.
        $hr = $this->buatAdmin(User::ROLE_HR);
        $direksi = $this->buatAdmin(User::ROLE_DIREKSI);

        $this->actingAs($hr, 'user')->post(route('panel.lembur.putuskan', $p), ['keputusan' => 'setuju'])->assertForbidden();
        $this->actingAs($hr, 'user')->post(route('panel.lembur.ketahui', $p))->assertSessionHas('sukses');
        $this->assertNotNull($p->fresh()->lembur_diketahui_pada);

        $this->actingAs($direksi, 'user')->post(route('panel.lembur.putuskan', $p), ['keputusan' => 'setuju'])->assertSessionHas('sukses');
        $this->assertSame(StatusPengajuan::Disetujui, $p->fresh()->lembur_status);

        $this->actingAs($direksi, 'user')->post(route('panel.lembur.putuskan', $p), ['keputusan' => 'tolak', 'catatan' => 'x'])->assertSessionHas('galat');
        $this->actingAs($direksi, 'user')->post(route('panel.lembur.kembalikan', $p))->assertSessionHas('sukses');
        $this->assertSame(StatusPengajuan::Menunggu, $p->fresh()->lembur_status);
    }

    // ------------------------------------------------------------ pulang lebih awal

    public function test_pulang_sebelum_jam_wajib_alasan_dan_belum_tercatat_tanpa_alasan(): void
    {
        $this->masukLalu('2026-11-16 13:00:00'); // 240 menit sebelum 17:00

        $this->kirim(self::DI_KANTOR)->assertStatus(422)->assertJson(['kode' => 'pulang_cepat']);
        $this->assertNull(Presensi::query()->first()->jam_out);

        $this->kirim(self::DI_KANTOR, ['alasan_pulang_cepat' => 'x'])->assertStatus(422)->assertJson(['kode' => 'pulang_cepat']);
        $this->assertNull(Presensi::query()->first()->jam_out);
    }

    public function test_pulang_awal_dengan_alasan_tercatat_menunggu_dan_direksi_memutuskan(): void
    {
        $this->masukLalu('2026-11-16 13:00:00');

        $this->kirim(self::DI_KANTOR, ['alasan_pulang_cepat' => 'Anak sakit, dijemput dari sekolah'])
            ->assertOk()->assertJson(['jenis' => 'pulang', 'pulang_cepat_menit' => 240, 'lembur_menit' => 0]);

        $p = Presensi::query()->firstOrFail();
        $this->assertSame('13:00:00', $p->jam_out);
        $this->assertSame(StatusPengajuan::Menunggu, $p->pulang_cepat_status);
        $this->assertNull($p->lembur_status);

        $hr = $this->buatAdmin(User::ROLE_HR);
        $direksi = $this->buatAdmin(User::ROLE_DIREKSI);

        $this->actingAs($hr, 'user')->post(route('panel.pulang-cepat.putuskan', $p), ['keputusan' => 'setuju'])->assertForbidden();
        $this->actingAs($hr, 'user')->post(route('panel.pulang-cepat.ketahui', $p))->assertSessionHas('sukses');
        $this->assertNotNull($p->fresh()->pulang_cepat_diketahui_pada);

        $this->actingAs($direksi, 'user')->post(route('panel.pulang-cepat.putuskan', $p), ['keputusan' => 'setuju'])->assertSessionHas('sukses');
        $this->assertSame(StatusPengajuan::Disetujui, $p->fresh()->pulang_cepat_status);
    }

    public function test_pulang_awal_yang_disetujui_tidak_dihitung_pulang_cepat_di_rekap(): void
    {
        $this->masukLalu('2026-11-16 13:00:00');
        $this->kirim(self::DI_KANTOR, ['alasan_pulang_cepat' => 'Keperluan keluarga mendesak'])->assertOk();
        $p = Presensi::query()->firstOrFail();
        $k = \App\Models\Karyawan::query()->findOrFail('1001');
        $rekap = app(\App\Services\RekapService::class);
        $hari = Carbon::parse('2026-11-16')->startOfDay();

        $ambil = fn () => $rekap->baris($k, $rekap->muat(collect([$k]), $hari, $hari), $hari, $hari)->first();

        $this->assertTrue($ambil()['pulang_cepat']); // menunggu: tetap dihitung
        app(\App\Services\PulangCepatService::class)->putuskan($p->fresh(), StatusPengajuan::Disetujui, $this->buatAdmin(User::ROLE_DIREKSI));
        $this->assertFalse($ambil()['pulang_cepat']); // disetujui: tidak dihitung
    }

    // ------------------------------------------------------------ koreksi presensi oleh HR

    private function koreksi(array $o = [])
    {
        return $this->post(route('panel.koreksi.store'), array_merge([
            'nik' => '1001', 'tanggal' => '2026-11-13', 'jam_masuk' => '08:55', 'jam_pulang' => '17:00',
            'alasan' => 'HP karyawan rusak seharian',
        ], $o));
    }

    public function test_hr_membuat_presensi_baru_lewat_koreksi(): void
    {
        $this->buatKaryawan();
        $this->actingAs($this->buatAdmin(User::ROLE_HR), 'user');

        $this->koreksi()->assertRedirect(route('panel.koreksi.index'))->assertSessionHas('sukses');

        $p = Presensi::query()->where('nik', '1001')->where('tanggal_presensi', '2026-11-13')->firstOrFail();
        $this->assertSame('08:55:00', $p->jam_in);
        $this->assertSame('17:00:00', $p->jam_out);
        $this->assertTrue($p->dikoreksi());
        $this->assertSame('HP karyawan rusak seharian', $p->koreksi_alasan);
        $this->assertNull($p->koreksi_jam_in_asli);
        $this->assertDatabaseHas('log_aktivitas', ['aksi' => 'presensi.koreksi']);
    }

    public function test_koreksi_menyimpan_jam_asli_hanya_pada_koreksi_pertama(): void
    {
        $this->buatKaryawan();
        Presensi::query()->create(['nik' => '1001', 'tanggal_presensi' => '2026-11-13', 'jam_in' => '09:30:00', 'kode_jam_kerja' => 'JK01']);
        $this->actingAs($this->buatAdmin(User::ROLE_HR), 'user');

        $this->koreksi(['jam_pulang' => ''])->assertSessionHas('sukses');
        $p = Presensi::query()->where('nik', '1001')->firstOrFail();
        $this->assertSame('08:55:00', $p->jam_in);
        $this->assertSame('09:30:00', $p->koreksi_jam_in_asli);

        $this->koreksi(['jam_masuk' => '08:40', 'jam_pulang' => '17:05'])->assertSessionHas('sukses');
        $p = $p->fresh();
        $this->assertSame('08:40:00', $p->jam_in);
        $this->assertSame('17:05:00', $p->jam_out);
        $this->assertSame('09:30:00', $p->koreksi_jam_in_asli); // tidak tertimpa
    }

    public function test_koreksi_ditolak_pada_kasus_yang_tidak_valid(): void
    {
        $this->buatKaryawan();
        $this->actingAs($this->buatAdmin(User::ROLE_HR), 'user');

        $this->koreksi(['tanggal' => '2026-11-17'])->assertSessionHas('galat');                  // masa depan
        $this->koreksi(['tanggal' => '2026-11-15'])->assertSessionHas('galat');                  // Minggu
        $this->koreksi(['tanggal' => '2026-09-01'])->assertSessionHas('galat');                  // lewat batas hari mundur
        $this->koreksi(['jam_masuk' => '17:00', 'jam_pulang' => '09:00'])->assertSessionHas('galat'); // pulang < masuk
        $this->koreksi(['jam_masuk' => '', 'jam_pulang' => '17:00'])->assertSessionHas('galat');       // belum ada presensi masuk
        $this->koreksi(['alasan' => 'singkat'])->assertSessionHasErrors('alasan');
        $this->assertDatabaseCount('presensi', 0);

        \App\Models\HariLibur::query()->create(['tanggal' => '2026-11-13', 'keterangan' => 'Libur uji']);
        $this->koreksi()->assertSessionHas('galat');
        $this->assertDatabaseCount('presensi', 0);
    }

    public function test_direksi_dan_karyawan_tidak_bisa_mengoreksi(): void
    {
        $this->buatKaryawan();

        $this->actingAs($this->buatAdmin(User::ROLE_DIREKSI), 'user');
        $this->koreksi()->assertForbidden();
        $this->get(route('panel.koreksi.index'))->assertForbidden();
        $this->assertDatabaseCount('presensi', 0);
    }
}
