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
}
