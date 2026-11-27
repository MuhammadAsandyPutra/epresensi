<?php

namespace Tests\Feature;

use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Models\User;
use App\Services\PengajuanService;
use App\Services\RekapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MembuatData;
use Tests\TestCase;

/** Pengaman terhadap loop tak berujung dan kiriman raksasa (penyebab memori habis). */
class BatasMemoriTest extends TestCase
{
    use MembuatData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('presensi.foto.disk'));
        Carbon::setTestNow('2026-11-16 08:00:00'); // Senin
        $this->siapkanMaster();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Regresi bug asli: muat($k, $hari, $hari) memakai SATU objek tanggal untuk awal dan akhir. */
    public function test_rekap_dengan_awal_dan_akhir_objek_sama_berhenti_dan_tidak_mengubah_tanggal(): void
    {
        $k = $this->buatKaryawan();
        $service = app(PengajuanService::class);
        $p = $service->ajukan($k, ['jenis' => 3, 'tgl_izin' => '2026-11-16', 'tgl_selesai' => '2026-11-18', 'keterangan' => 'Acara keluarga']);
        $service->putuskan($p, StatusPengajuan::Disetujui, $this->buatAdmin(User::ROLE_DIREKSI));

        $hari = now()->startOfDay();
        $ctx = app(RekapService::class)->muat(collect([$k]), $hari, $hari);

        $this->assertSame('2026-11-16', $hari->toDateString()); // tidak tergeser oleh addDay()
        $this->assertArrayHasKey('2026-11-16', $ctx['dikecualikan']['1001']);
        $this->assertArrayNotHasKey('2026-11-17', $ctx['dikecualikan']['1001']); // di luar rentang laporan
    }

    public function test_rentang_pengajuan_yang_tidak_masuk_akal_ditolak_sebelum_dihitung(): void
    {
        $k = $this->buatKaryawan();

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('terlalu panjang');

        app(PengajuanService::class)->ajukan($k, ['jenis' => 1, 'tgl_izin' => '2026-11-16', 'tgl_selesai' => '9999-12-31', 'keterangan' => 'Salah ketik tahun']);
    }

    public function test_foto_raksasa_ditolak_sebelum_didekode(): void
    {
        $this->actingAs($this->buatKaryawan(), 'karyawan');

        $this->postJson(route('presensi.store'), [
            'lokasi' => '-3.388050,114.635020', 'akurasi' => 10,
            'foto' => 'data:image/jpeg;base64,'.str_repeat('A', 3_000_000),
        ])->assertStatus(422)->assertJsonValidationErrors('foto');
        $this->assertDatabaseCount('presensi', 0);
    }
}
