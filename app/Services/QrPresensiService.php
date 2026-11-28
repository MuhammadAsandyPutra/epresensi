<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\Pengaturan;
use Carbon\CarbonInterface;

/**
 * Token QR presensi. Dua mode (Pengaturan `qr.mode`):
 *
 *  - `tetap` (bawaan): satu QR per cabang untuk dicetak/ditempel. QR HANYA berubah saat Admin menekan "Ganti QR"
 *    (menaikkan `cabang.qr_versi`); QR lama langsung tidak berlaku. Tidak butuh perangkat yang menyala terus.
 *  - `berganti`: QR berganti otomatis tiap `berlaku_detik` di layar kantor. Token berlaku pada slot waktu saat ini dan
 *    SATU slot sebelumnya (1x-2x interval), jadi foto QR yang dikirim ke luar cepat kedaluwarsa.
 *
 * Token tidak disimpan di database: ia bertanda tangan (HMAC dari APP_KEY) atas kode cabang + versi/slot. Mengganti
 * APP_KEY membuat semua QR tetap tidak berlaku (cetak ulang). Kode QR saja BUKAN bukti kehadiran: GPS tetap divalidasi
 * di server, dan pada mode `tetap` itulah satu-satunya penghalang bagi foto QR yang beredar.
 */
class QrPresensiService
{
    public const MODE_TETAP = 'tetap';

    public const MODE_BERGANTI = 'berganti';

    public function aktif(): bool
    {
        return (bool) Pengaturan::ambil('qr.aktif');
    }

    public function mode(): string
    {
        return Pengaturan::ambil('qr.mode') === self::MODE_BERGANTI ? self::MODE_BERGANTI : self::MODE_TETAP;
    }

    /** Lama satu slot (detik) pada mode berganti, dibatasi 15-600 agar nilai yang salah tidak membuat QR tak bisa dipakai. */
    public function ttl(): int
    {
        return max(15, min(600, (int) Pengaturan::ambil('qr.berlaku_detik')));
    }

    /**
     * @return array{token: string, mode: string, ttl: int|null, sisa_detik: int|null, versi: int|null}
     *                                                                                                  ttl/sisa_detik hanya terisi pada mode berganti; versi hanya pada mode tetap
     */
    public function buat(string $kodeCabang, ?CarbonInterface $waktu = null): array
    {
        if ($this->mode() === self::MODE_BERGANTI) {
            $ts = ($waktu ?? now())->getTimestamp();
            $ttl = $this->ttl();
            $slot = intdiv($ts, $ttl);

            return [
                'token' => $slot.'.'.$this->tandaBerganti($kodeCabang, $slot),
                'mode' => self::MODE_BERGANTI, 'ttl' => $ttl, 'sisa_detik' => ($slot + 1) * $ttl - $ts, 'versi' => null,
            ];
        }

        $versi = $this->versi($kodeCabang);

        return ['token' => 'T.'.$this->tandaTetap($kodeCabang, $versi), 'mode' => self::MODE_TETAP, 'ttl' => null, 'sisa_detik' => null, 'versi' => $versi];
    }

    /** Token sah hanya untuk cabang yang sama dan hanya menurut mode yang sedang aktif. */
    public function sah(string $token, string $kodeCabang): bool
    {
        return $this->mode() === self::MODE_BERGANTI
            ? $this->sahBerganti($token, $kodeCabang)
            : $this->sahTetap($token, $kodeCabang);
    }

    /** Ganti QR cabang: QR lama (yang sudah dicetak) langsung tidak berlaku. Mengembalikan nomor versi baru. */
    public function ganti(Cabang $cabang): int
    {
        $baru = max(1, (int) $cabang->qr_versi) + 1;
        $cabang->forceFill(['qr_versi' => $baru, 'qr_diganti_pada' => now()])->save();

        return $baru;
    }

    private function sahTetap(string $token, string $kodeCabang): bool
    {
        if (! preg_match('/^T\.([A-Za-z0-9_-]{16,40})$/', $token, $m)) {
            return false;
        }

        return hash_equals($this->tandaTetap($kodeCabang, $this->versi($kodeCabang)), $m[1]);
    }

    private function sahBerganti(string $token, string $kodeCabang): bool
    {
        if (! preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{16,40})$/', $token, $m)) {
            return false;
        }

        $slot = (int) $m[1];
        $sekarang = intdiv(now()->getTimestamp(), $this->ttl());
        if ($slot > $sekarang || $slot < $sekarang - 1) {
            return false;
        }

        return hash_equals($this->tandaBerganti($kodeCabang, $slot), $m[2]);
    }

    private function versi(string $kodeCabang): int
    {
        return max(1, (int) Cabang::query()->whereKey($kodeCabang)->value('qr_versi'));
    }

    private function tandaBerganti(string $kodeCabang, int $slot): string
    {
        return $this->tanda("presensi-qr|{$kodeCabang}|{$slot}");
    }

    private function tandaTetap(string $kodeCabang, int $versi): string
    {
        return $this->tanda("presensi-qr-tetap|{$kodeCabang}|{$versi}");
    }

    private function tanda(string $pesan): string
    {
        $mentah = hash_hmac('sha256', $pesan, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(substr($mentah, 0, 16)), '+/', '-_'), '=');
    }
}
