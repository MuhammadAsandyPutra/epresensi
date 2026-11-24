<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Akun panel (guard `user`): role `admin`, `hr`, atau `direksi`. */
class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_HR = 'hr';

    public const ROLE_DIREKSI = 'direksi';

    /** Daftar role beserta namanya (dipakai form pengelolaan akun). */
    public const ROLE_LABEL = [
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_HR => 'HR',
        self::ROLE_DIREKSI => 'Direksi',
    ];

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isHr(): bool
    {
        return $this->role === self::ROLE_HR;
    }

    public function isDireksi(): bool
    {
        return $this->role === self::ROLE_DIREKSI;
    }

    /** Hanya Direksi yang memutuskan izin/sakit/cuti/tugas luar/lembur. Ubah di sini bila aturannya berubah. */
    public function bolehMemutuskan(): bool
    {
        return $this->isDireksi();
    }

    /** Admin & HR menerima pemberitahuan ("diketahui") atas pengajuan dan lembur. */
    public function penerimaInfo(): bool
    {
        return $this->isAdmin() || $this->isHr();
    }
}
