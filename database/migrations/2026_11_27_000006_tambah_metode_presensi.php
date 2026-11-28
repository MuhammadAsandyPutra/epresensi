<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Metode presensi: `foto` (selfie + GPS), `qr` (scan QR kantor + GPS tanpa foto), `campuran` (masuk dan pulang berbeda). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensi', function (Blueprint $t) {
            if (! Schema::hasColumn('presensi', 'metode')) {
                $t->string('metode', 10)->default('foto');
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong (lihat catatan pada migrasi tabel master).
    }
};
