<?php

namespace Tests\Feature;

use App\Enums\StatusPengajuan;
use App\Models\Karyawan;
use App\Models\Presensi;
use App\Models\PresensiTahan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MembuatData;
use Tests\TestCase;

class PresensiLuarKantorTest extends TestCase
{
    use MembuatData, RefreshDatabase;

    private const DI_KANTOR = '-3.388050,114.635020';

    private const DI_LUAR = '-3.390500,114.635000'; // ± 278 m dari kantor (radius 100 m)

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-16 08:30:00'); // Senin
        $this->siapkanMaster();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function karyawanLuar(bool $boleh = true): Karyawan
    {
        $k = $this->buatKaryawan('1001', ['boleh_presensi_luar' => $boleh]);
        $this->actingAs($k, 'karyawan');

        return $k;
    }

    private function kirim(string $lokasi, array $lebih = [])
    {
        return $this->postJson(route('presensi.store'), array_merge(['lokasi' => $lokasi, 'akurasi' => 10, 'foto' => $this->fotoDataUrl()], $lebih));
    }

    /** Presensi masuk luar kantor (ditahan) pukul 08:30. */
    private function masukLuar(): PresensiTahan
    {
        $this->kirim(self::DI_LUAR, ['alasan_luar_kantor' => 'Menagih angsuran nasabah di Pasar Tanjung'])
            ->assertOk()->assertJson(['ok' => true, 'jenis' => 'masuk', 'ditahan' => true]);

        return PresensiTahan::query()->firstOrFail();
    }

    public function test_karyawan_tidak_ditandai_tetap_ditolak_di_luar_radius(): void
    {
        $this->karyawanLuar(false);

        $this->kirim(self::DI_LUAR, ['alasan_luar_kantor' => 'Menagih angsuran'])->assertStatus(422)->assertJson(['kode' => 'radius']);
        $this->assertDatabaseCount('presensi_tahan', 0);
        $this->assertDatabaseCount('presensi', 0);
    }

    public function test_karyawan_ditandai_di_dalam_radius_tercatat_langsung(): void
    {
        $this->karyawanLuar();

        $this->kirim(self::DI_KANTOR)->assertOk()->assertJsonMissing(['ditahan' => true]);
        $this->assertDatabaseCount('presensi', 1);
        $this->assertDatabaseCount('presensi_tahan', 0);
    }

    public function test_di_luar_radius_wajib_tujuan_dan_belum_menyimpan_apa_pun(): void
    {
        $this->karyawanLuar();

        $this->kirim(self::DI_LUAR)->assertStatus(422)->assertJson(['kode' => 'luar_kantor']);
        $this->kirim(self::DI_LUAR, ['alasan_luar_kantor' => 'x'])->assertStatus(422)->assertJson(['kode' => 'luar_kantor']);
        $this->assertDatabaseCount('presensi_tahan', 0);
        $this->assertDatabaseCount('presensi', 0);
        $this->assertSame([], Storage::disk('local')->allFiles()); // tidak ada foto yang tertinggal
    }

    public function test_presensi_luar_ditahan_belum_tercatat_di_presensi(): void
    {
        $this->karyawanLuar();
        $t = $this->masukLuar();

        $this->assertSame('08:30:00', $t->jam_in);
        $this->assertSame(StatusPengajuan::Menunggu, $t->status);
        $this->assertDatabaseCount('presensi', 0); // ditahan: belum dihitung

        // Mencoba presensi lagi tidak membuat masuk ganda: yang berikutnya adalah pulang (tertahan juga).
        Carbon::setTestNow('2026-11-16 17:10:00');
        $this->kirim(self::DI_KANTOR)->assertOk()->assertJson(['jenis' => 'pulang', 'ditahan' => true]);
        $this->kirim(self::DI_KANTOR)->assertStatus(422)->assertJson(['kode' => 'selesai']);
        $this->assertSame('17:10:00', $t->fresh()->jam_out);
        $this->assertDatabaseCount('presensi', 0);
    }

    public function test_direksi_menyetujui_lalu_tercatat_dengan_jam_asli(): void
    {
        $this->karyawanLuar();
        $t = $this->masukLuar();
        Carbon::setTestNow('2026-11-16 17:10:00');
        $this->kirim(self::DI_KANTOR)->assertOk();

        // Disetujui jauh sesudahnya: jam yang dipakai tetap jam saat karyawan presensi.
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->actingAs($this->buatAdmin(User::ROLE_DIREKSI), 'user')
            ->post(route('panel.presensi-luar.putuskan', $t), ['keputusan' => 'setuju'])->assertSessionHas('sukses');

        $p = Presensi::query()->where('nik', '1001')->where('tanggal_presensi', '2026-11-16')->firstOrFail();
        $this->assertSame('08:30:00', $p->jam_in);
        $this->assertSame('17:10:00', $p->jam_out);
        $this->assertTrue($p->luar_kantor);
        $this->assertSame(StatusPengajuan::Disetujui, $t->fresh()->status);
    }

    public function test_ditolak_tidak_pernah_tercatat_dan_bisa_dikembalikan(): void
    {
        $this->karyawanLuar();
        $t = $this->masukLuar();
        $direksi = $this->buatAdmin(User::ROLE_DIREKSI);

        $this->actingAs($direksi, 'user')->post(route('panel.presensi-luar.putuskan', $t), ['keputusan' => 'tolak'])->assertSessionHasErrors('catatan');
        $this->actingAs($direksi, 'user')->post(route('panel.presensi-luar.putuskan', $t), ['keputusan' => 'tolak', 'catatan' => 'Tidak sesuai tugas'])->assertSessionHas('sukses');
        $this->assertDatabaseCount('presensi', 0);
        $this->assertSame(StatusPengajuan::Ditolak, $t->fresh()->status);

        $this->actingAs($direksi, 'user')->post(route('panel.presensi-luar.kembalikan', $t))->assertSessionHas('sukses');
        $this->assertSame(StatusPengajuan::Menunggu, $t->fresh()->status);
    }

    public function test_hr_tidak_bisa_memutuskan_hanya_mengetahui(): void
    {
        $this->karyawanLuar();
        $t = $this->masukLuar();
        $hr = $this->buatAdmin(User::ROLE_HR);

        $this->actingAs($hr, 'user')->post(route('panel.presensi-luar.putuskan', $t), ['keputusan' => 'setuju'])->assertForbidden();
        $this->assertDatabaseCount('presensi', 0);

        $this->actingAs($hr, 'user')->post(route('panel.presensi-luar.ketahui', $t))->assertSessionHas('sukses');
        $this->assertNotNull($t->fresh()->diketahui_pada);
        $this->actingAs($hr, 'user')->get(route('panel.presensi-luar.index'))->assertOk();
    }

    public function test_masuk_di_kantor_lalu_pulang_di_luar_ditahan_dan_diterapkan_saat_disetujui(): void
    {
        $this->karyawanLuar();
        $this->kirim(self::DI_KANTOR)->assertOk();
        $this->assertDatabaseCount('presensi', 1);

        Carbon::setTestNow('2026-11-16 17:10:00');
        $this->kirim(self::DI_LUAR, ['alasan_luar_kantor' => 'Menagih angsuran sepulang kantor'])
            ->assertOk()->assertJson(['jenis' => 'pulang', 'ditahan' => true]);

        $this->assertNull(Presensi::query()->first()->jam_out); // belum tercatat
        $t = PresensiTahan::query()->firstOrFail();
        $this->assertNull($t->jam_in);
        $this->assertSame('17:10:00', $t->jam_out);

        $this->actingAs($this->buatAdmin(User::ROLE_DIREKSI), 'user')
            ->post(route('panel.presensi-luar.putuskan', $t), ['keputusan' => 'setuju'])->assertSessionHas('sukses');
        $this->assertSame('17:10:00', Presensi::query()->first()->jam_out);
    }

    public function test_konflik_dengan_koreksi_hr_menggagalkan_persetujuan(): void
    {
        $this->karyawanLuar();
        $t = $this->masukLuar();

        // HR sudah lebih dulu membuat presensi pada hari yang sama lewat Koreksi Presensi.
        Presensi::query()->create(['nik' => '1001', 'tanggal_presensi' => '2026-11-16', 'jam_in' => '09:00:00', 'kode_jam_kerja' => 'JK01']);

        $this->actingAs($this->buatAdmin(User::ROLE_DIREKSI), 'user')
            ->post(route('panel.presensi-luar.putuskan', $t), ['keputusan' => 'setuju'])->assertSessionHas('galat');
        $this->assertSame(StatusPengajuan::Menunggu, $t->fresh()->status); // transaksi dibatalkan
        $this->assertSame('09:00:00', Presensi::query()->first()->jam_in);
    }
}
