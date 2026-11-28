<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR kantor mode "tetap": QR hanya berubah saat Admin menekan "Ganti QR". Yang disimpan hanya nomor VERSI;
 * token diturunkan dari APP_KEY + kode cabang + versi, jadi tidak ada rahasia yang tersimpan di database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabang', function (Blueprint $t) {
            if (! Schema::hasColumn('cabang', 'qr_versi')) {
                $t->unsignedInteger('qr_versi')->default(1);
            }
            if (! Schema::hasColumn('cabang', 'qr_diganti_pada')) {
                $t->timestamp('qr_diganti_pada')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong (lihat catatan pada migrasi tabel master).
    }
};
