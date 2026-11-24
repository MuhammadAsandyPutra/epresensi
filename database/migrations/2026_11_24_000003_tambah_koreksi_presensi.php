<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Koreksi presensi oleh HR/Admin: siapa, kapan, mengapa, dan jam aslinya (jejak audit). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensi', function (Blueprint $t) {
            if (! Schema::hasColumn('presensi', 'koreksi_oleh')) {
                $t->unsignedBigInteger('koreksi_oleh')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'koreksi_pada')) {
                $t->timestamp('koreksi_pada')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'koreksi_alasan')) {
                $t->string('koreksi_alasan', 500)->nullable();
            }
            if (! Schema::hasColumn('presensi', 'koreksi_jam_in_asli')) {
                $t->time('koreksi_jam_in_asli')->nullable();   // jam masuk SEBELUM koreksi pertama (null = belum ada presensi)
            }
            if (! Schema::hasColumn('presensi', 'koreksi_jam_out_asli')) {
                $t->time('koreksi_jam_out_asli')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong (lihat catatan pada migrasi tabel master).
    }
};
