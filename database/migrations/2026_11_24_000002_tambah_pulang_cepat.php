<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pulang lebih awal: alasan wajib saat presensi, keputusan Direksi sesudahnya, diketahui HR. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensi', function (Blueprint $t) {
            if (! Schema::hasColumn('presensi', 'pulang_cepat_menit')) {
                $t->unsignedSmallInteger('pulang_cepat_menit')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'alasan_pulang_cepat')) {
                $t->string('alasan_pulang_cepat', 500)->nullable();
            }
            if (! Schema::hasColumn('presensi', 'pulang_cepat_status')) {
                $t->unsignedTinyInteger('pulang_cepat_status')->nullable();   // null = pulang normal; 0/1/2 = StatusPengajuan
            }
            if (! Schema::hasColumn('presensi', 'pulang_cepat_catatan')) {
                $t->string('pulang_cepat_catatan', 255)->nullable();
            }
            if (! Schema::hasColumn('presensi', 'pulang_cepat_diputuskan_oleh')) {
                $t->unsignedBigInteger('pulang_cepat_diputuskan_oleh')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'pulang_cepat_diputuskan_pada')) {
                $t->timestamp('pulang_cepat_diputuskan_pada')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'pulang_cepat_diketahui_oleh')) {
                $t->unsignedBigInteger('pulang_cepat_diketahui_oleh')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'pulang_cepat_diketahui_pada')) {
                $t->timestamp('pulang_cepat_diketahui_pada')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong (lihat catatan pada migrasi tabel master).
    }
};
