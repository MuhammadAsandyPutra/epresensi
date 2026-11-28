<?php

namespace Tests\Feature;

use App\Enums\StatusPengajuan;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\Pengaturan;
use App\Models\Presensi;
use App\Models\User;
use App\Services\PengajuanService;
use App\Services\QrPresensiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MembuatData;
use Tests\TestCase;

/** Presensi tanpa foto dengan memindai QR kantor (mode tetap atau berganti otomatis) + GPS. */
class PresensiQrTest extends TestCase
{
    use MembuatData, RefreshDatabase;

    private const DI_KANTOR = '-3.388050,114.635020';

    private const DI_LUAR = '-3.390500,114.635000'; // ± 278 m dari kantor (radius 100 m)

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('presensi.foto.disk'));
        // 08:30:00 tepat di awal slot 60 detik (epoch lokal berkelipatan menit), sehingga batas slot mudah diuji.
        Carbon::setTestNow('2026-11-16 08:30:00'); // Senin
        $this->siapkanMaster();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Pengaturan::lupakan(); // cache pengaturan bersifat statis: jangan bocor ke test lain
        parent::tearDown();
    }

    /** Mode BERGANTI (layar kantor): token berganti tiap $ttl detik. */
    private function nyalakanQr(int $ttl = 60): void
    {
        Pengaturan::simpan('qr.aktif', 1);
        Pengaturan::simpan('qr.mode', 'berganti');
        Pengaturan::simpan('qr.berlaku_detik', $ttl);
    }

    /** Mode TETAP (dicetak): QR hanya berubah saat Admin menekan Ganti QR. */
    private function nyalakanQrTetap(): void
    {
        Pengaturan::simpan('qr.aktif', 1);
        Pengaturan::simpan('qr.mode', 'tetap');
    }

    private function token(string $cabang = 'PST'): string
    {
        return app(QrPresensiService::class)->buat($cabang)['token'];
    }

    private function kirimQr(string $lokasi, ?string $token = null, array $lebih = [])
    {
        return $this->postJson(route('presensi.qr.store'), array_merge(['token' => $token ?? $this->token(), 'lokasi' => $lokasi, 'akurasi' => 10], $lebih));
    }

    private function karyawan(array $lebih = []): Karyawan
    {
        $k = $this->buatKaryawan('1001', $lebih);
        $this->actingAs($k, 'karyawan');

        return $k;
    }

    // ------------------------------------------------------------ aktivasi & token

    public function test_qr_nonaktif_ditolak_dan_halaman_mengarahkan_ke_presensi_foto(): void
    {
        $this->karyawan();

        $this->kirimQr(self::DI_KANTOR)->assertStatus(422)->assertJson(['kode' => 'qr']);
        $this->get(route('presensi.qr'))->assertRedirect(route('presensi.create'));
        $this->assertDatabaseCount('presensi', 0);
    }

    public function test_token_dibuat_per_cabang_dan_hanya_sah_pada_slot_sekarang_atau_sebelumnya(): void
    {
        $this->nyalakanQr(60);
        $qr = app(QrPresensiService::class);
        $token = $qr->buat('PST')['token'];

        $this->assertTrue($qr->sah($token, 'PST'));
        $this->assertFalse($qr->sah($token, 'CBG'), 'token cabang lain harus ditolak');
        $ganti = str_ends_with($token, 'A') ? 'B' : 'A';
        $this->assertFalse($qr->sah(substr($token, 0, -1).$ganti, 'PST'), 'token yang diubah harus ditolak');
        $this->assertFalse($qr->sah('bukan-token', 'PST'));

        Carbon::setTestNow(now()->addSeconds(59));
        $this->assertTrue($qr->sah($token, 'PST'));  // masih slot yang sama
        Carbon::setTestNow(now()->addSeconds(1));    // slot berikutnya: token slot lalu masih boleh
        $this->assertTrue($qr->sah($token, 'PST'));
        Carbon::setTestNow(now()->addSeconds(60));   // dua slot kemudian: kedaluwarsa
        $this->assertFalse($qr->sah($token, 'PST'));
    }

    public function test_token_dari_masa_depan_ditolak(): void
    {
        $this->nyalakanQr(60);
        $qr = app(QrPresensiService::class);
        $masaDepan = $qr->buat('PST', now()->addMinutes(10))['token'];

        $this->assertFalse($qr->sah($masaDepan, 'PST'));
    }

    // ------------------------------------------------------------ presensi

    public function test_masuk_dengan_qr_tanpa_foto_tercatat(): void
    {
        $this->nyalakanQr();
        $this->karyawan();

        $this->kirimQr(self::DI_KANTOR)->assertOk()->assertJson(['ok' => true, 'jenis' => 'masuk']);

        $p = Presensi::query()->firstOrFail();
        $this->assertSame('qr', $p->metode);
        $this->assertNull($p->foto_in);
        $this->assertSame('08:30:00', $p->jam_in);
        $this->assertSame([], Storage::disk(config('presensi.foto.disk'))->allFiles()); // tidak ada foto yang disimpan
    }

    public function test_qr_kedaluwarsa_atau_cabang_lain_ditolak_dan_tidak_mencatat(): void
    {
        $this->nyalakanQr();
        $this->karyawan();

        $this->kirimQr(self::DI_KANTOR, $this->token('CBG'))->assertStatus(422)->assertJson(['kode' => 'qr']);

        $lama = $this->token();
        Carbon::setTestNow(now()->addSeconds(130));
        $this->kirimQr(self::DI_KANTOR, $lama)->assertStatus(422)->assertJson(['kode' => 'qr']);

        $this->assertDatabaseCount('presensi', 0);
    }

    public function test_qr_di_luar_radius_ditolak_tanpa_menyebut_jarak(): void
    {
        $this->nyalakanQr();
        $this->karyawan();

        $r = $this->kirimQr(self::DI_LUAR)->assertStatus(422)->assertJson(['kode' => 'radius']);
        $this->assertStringNotContainsString(' m dari', $r->json('pesan'));
        $this->assertDatabaseCount('presensi', 0);
    }

    public function test_qr_tidak_dilonggarkan_oleh_izin_luar_kantor_maupun_tugas_luar(): void
    {
        $this->nyalakanQr();
        $k = $this->karyawan(['boleh_presensi_luar' => true]);

        // Tugas luar disetujui: presensi FOTO boleh di luar radius, tetapi QR tetap harus di kantor.
        $service = app(PengajuanService::class);
        $p = $service->ajukan($k, ['jenis' => 4, 'tgl_izin' => '2026-11-16', 'tgl_selesai' => '2026-11-16', 'keterangan' => 'Kunjungan', 'lokasi_tugas' => 'Cabang']);
        $service->putuskan($p, StatusPengajuan::Disetujui, $this->buatAdmin(User::ROLE_DIREKSI));

        $this->kirimQr(self::DI_LUAR)->assertStatus(422)->assertJson(['kode' => 'radius']);
        $this->assertDatabaseCount('presensi', 0);
        $this->assertDatabaseCount('presensi_tahan', 0); // QR tidak pernah menahan presensi
    }

    public function test_pulang_qr_melewati_batas_meminta_keterangan_lembur(): void
    {
        $this->nyalakanQr();
        $this->karyawan();
        $this->kirimQr(self::DI_KANTOR)->assertOk();

        Carbon::setTestNow('2026-11-16 18:30:00');
        $this->kirimQr(self::DI_KANTOR)->assertStatus(422)->assertJson(['kode' => 'lembur']);
        $this->kirimQr(self::DI_KANTOR, null, ['keterangan_lembur' => 'Menutup pembukuan akhir bulan'])->assertOk()->assertJson(['jenis' => 'pulang', 'lembur_menit' => 90]);

        $p = Presensi::query()->firstOrFail();
        $this->assertSame('qr', $p->metode);
        $this->assertSame(StatusPengajuan::Menunggu, $p->lembur_status);
    }

    public function test_masuk_dengan_foto_pulang_dengan_qr_menjadi_campuran(): void
    {
        $this->nyalakanQr();
        $this->karyawan();

        $this->postJson(route('presensi.store'), ['lokasi' => self::DI_KANTOR, 'akurasi' => 10, 'foto' => $this->fotoDataUrl()])->assertOk();
        $this->assertSame('foto', Presensi::query()->first()->metode);

        Carbon::setTestNow('2026-11-16 17:10:00');
        $this->kirimQr(self::DI_KANTOR)->assertOk()->assertJson(['jenis' => 'pulang']);
        $this->assertSame('campuran', Presensi::query()->first()->metode);
    }

    public function test_presensi_ganda_ditolak_di_qr(): void
    {
        $this->nyalakanQr();
        $this->karyawan();
        $this->kirimQr(self::DI_KANTOR)->assertOk();
        Carbon::setTestNow('2026-11-16 17:10:00');
        $this->kirimQr(self::DI_KANTOR)->assertOk();

        $this->kirimQr(self::DI_KANTOR)->assertStatus(422)->assertJson(['kode' => 'selesai']);
    }

    // ------------------------------------------------------------ layar QR kantor & pengaturan

    public function test_layar_qr_kantor_hanya_untuk_hr_dan_admin(): void
    {
        $this->nyalakanQr();
        $hr = $this->buatAdmin(User::ROLE_HR);

        $this->actingAs($hr, 'user')->get(route('panel.qr-kantor'))->assertOk()->assertSee('Mode QR berganti');
        $res = $this->actingAs($hr, 'user')->getJson(route('panel.qr-kantor.token', ['cabang' => 'PST']))->assertOk();

        parse_str(parse_url($res->json('url'), PHP_URL_QUERY), $query);
        $this->assertTrue(app(QrPresensiService::class)->sah($query['t'], 'PST'));
        $this->assertSame('berganti', $res->json('mode'));
        $this->assertSame(60, $res->json('sisa')); // tepat di awal slot
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $this->actingAs($hr, 'user')->getJson(route('panel.qr-kantor.token', ['cabang' => 'TIDAKADA']))->assertStatus(422);

        $this->actingAs($this->buatAdmin(User::ROLE_DIREKSI), 'user')->get(route('panel.qr-kantor'))->assertForbidden();
    }

    public function test_halaman_pengaturan_menampilkan_opsi_qr(): void
    {
        $this->actingAs($this->buatAdmin(User::ROLE_ADMIN), 'user')
            ->get(route('panel.pengaturan'))->assertOk()->assertSee('Presensi dengan QR')->assertSee('Jenis QR');
    }

    public function test_karyawan_tidak_dapat_membuka_layar_atau_token_kantor(): void
    {
        $this->nyalakanQr();
        $this->actingAs($this->buatKaryawan(), 'karyawan')
            ->get(route('panel.qr-kantor.token', ['cabang' => 'PST']))->assertRedirect(route('panel.login'));
    }

    // ------------------------------------------------------------ mode TETAP (dicetak, diganti manual)

    public function test_mode_tetap_qr_tidak_berubah_dari_waktu_ke_waktu(): void
    {
        $this->nyalakanQrTetap();
        $qr = app(QrPresensiService::class);
        $token = $qr->buat('PST')['token'];

        $this->assertSame('tetap', $qr->mode());
        $this->assertStringStartsWith('T.', $token);
        $this->assertNull($qr->buat('PST')['sisa_detik']);

        Carbon::setTestNow(now()->addDays(30)); // sebulan kemudian
        $this->assertSame($token, $qr->buat('PST')['token']);
        $this->assertTrue($qr->sah($token, 'PST'));
    }

    public function test_mode_tetap_presensi_dengan_qr_tercatat_dan_per_cabang(): void
    {
        $this->nyalakanQrTetap();
        $this->karyawan();
        Cabang::query()->create(['kode_cabang' => 'CBG', 'nama_cabang' => 'Cabang B', 'lokasi_cabang' => '-3.400000,114.600000', 'radius_cabang' => 100]);

        $this->kirimQr(self::DI_KANTOR, $this->token('CBG'))->assertStatus(422)->assertJson(['kode' => 'qr']); // QR cabang lain
        $this->assertDatabaseCount('presensi', 0);

        $this->kirimQr(self::DI_KANTOR, $this->token('PST'))->assertOk()->assertJson(['jenis' => 'masuk']);
        $this->assertSame('qr', Presensi::query()->firstOrFail()->metode);
    }

    public function test_ganti_qr_membuat_qr_lama_tidak_berlaku(): void
    {
        $this->nyalakanQrTetap();
        $qr = app(QrPresensiService::class);
        $lama = $qr->buat('PST')['token'];

        $versi = $qr->ganti(Cabang::query()->findOrFail('PST'));
        $baru = $qr->buat('PST')['token'];

        $this->assertSame(2, $versi);
        $this->assertNotSame($lama, $baru);
        $this->assertFalse($qr->sah($lama, 'PST'), 'QR lama yang sudah dicetak harus ditolak');
        $this->assertTrue($qr->sah($baru, 'PST'));

        $this->karyawan();
        $this->kirimQr(self::DI_KANTOR, $lama)->assertStatus(422)->assertJson(['kode' => 'qr']);
        $this->kirimQr(self::DI_KANTOR, $baru)->assertOk();
    }

    public function test_tetap_di_luar_radius_tetap_ditolak_karena_gps_adalah_penghalang_utama(): void
    {
        $this->nyalakanQrTetap();
        $this->karyawan();

        // QR sah (mis. fotonya beredar), tetapi lokasi tidak di kantor.
        $this->kirimQr(self::DI_LUAR, $this->token())->assertStatus(422)->assertJson(['kode' => 'radius']);
        $this->assertDatabaseCount('presensi', 0);
    }

    public function test_token_satu_mode_ditolak_di_mode_lain(): void
    {
        $qr = app(QrPresensiService::class);

        $this->nyalakanQr(60);
        $berganti = $qr->buat('PST')['token'];
        Pengaturan::simpan('qr.mode', 'tetap');
        $tetap = $qr->buat('PST')['token'];

        $this->assertFalse($qr->sah($berganti, 'PST'));   // mode tetap menolak token berganti
        Pengaturan::simpan('qr.mode', 'berganti');
        $this->assertFalse($qr->sah($tetap, 'PST'));      // mode berganti menolak token tetap
    }

    public function test_mode_tetap_bawaan_bila_pengaturan_belum_diubah(): void
    {
        Pengaturan::simpan('qr.aktif', 1); // mode tidak diatur sama sekali

        $this->assertSame('tetap', app(QrPresensiService::class)->mode());
    }

    public function test_hr_dapat_menampilkan_dan_mengganti_qr_kantor_mode_tetap(): void
    {
        $this->nyalakanQrTetap();
        $hr = $this->buatAdmin(User::ROLE_HR);

        $this->actingAs($hr, 'user')->get(route('panel.qr-kantor'))->assertOk()->assertSee('Mode QR tetap');
        $res = $this->actingAs($hr, 'user')->getJson(route('panel.qr-kantor.token', ['cabang' => 'PST']))->assertOk();
        $this->assertSame('tetap', $res->json('mode'));
        $this->assertNull($res->json('sisa'));
        $this->assertSame(1, $res->json('versi'));

        $this->actingAs($hr, 'user')->post(route('panel.qr-kantor.ganti'), ['cabang' => 'PST'])
            ->assertRedirect(route('panel.qr-kantor', ['cabang' => 'PST']))->assertSessionHas('sukses');
        $this->assertSame(2, (int) Cabang::query()->find('PST')->qr_versi);
        $this->assertNotNull(Cabang::query()->find('PST')->qr_diganti_pada);
        $this->assertDatabaseHas('log_aktivitas', ['aksi' => 'qr.ganti']);
    }

    public function test_direksi_tidak_dapat_mengganti_qr(): void
    {
        $this->nyalakanQrTetap();

        $this->actingAs($this->buatAdmin(User::ROLE_DIREKSI), 'user')->post(route('panel.qr-kantor.ganti'), ['cabang' => 'PST'])->assertForbidden();
        $this->assertSame(1, (int) Cabang::query()->find('PST')->qr_versi);
    }

    public function test_karyawan_tidak_dapat_mengganti_qr(): void
    {
        $this->nyalakanQrTetap();

        $this->actingAs($this->buatKaryawan(), 'karyawan')->post(route('panel.qr-kantor.ganti'), ['cabang' => 'PST'])->assertRedirect(route('panel.login'));
        $this->assertSame(1, (int) Cabang::query()->find('PST')->qr_versi);
    }
}
