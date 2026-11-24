<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PenggunaRequest;
use App\Models\LogAktivitas;
use App\Models\PengajuanIzin;
use App\Models\Presensi;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Pengelolaan akun panel (Admin, HR, Direksi). Hanya untuk Admin. */
class PenggunaController extends Controller
{
    public function index(): View
    {
        return view('admin.pengguna.index', ['daftar' => User::query()->orderBy('role')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.pengguna.form', ['pengguna' => new User(['role' => User::ROLE_HR])]);
    }

    public function store(PenggunaRequest $request): RedirectResponse
    {
        $u = User::query()->create($request->validated());
        LogAktivitas::catat('pengguna.tambah', "{$u->email} ({$u->role})");

        return redirect()->route('panel.pengguna.index')->with('sukses', 'Akun ditambahkan.');
    }

    public function edit(User $pengguna): View
    {
        return view('admin.pengguna.form', ['pengguna' => $pengguna]);
    }

    public function update(PenggunaRequest $request, User $pengguna): RedirectResponse
    {
        $data = $request->validated();

        if ($pengguna->isAdmin() && $data['role'] !== User::ROLE_ADMIN && $this->adminTerakhir($pengguna)) {
            return back()->withInput()->with('galat', 'Ini satu-satunya Admin; role-nya tidak boleh diubah.');
        }
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $pengguna->update($data);
        LogAktivitas::catat('pengguna.ubah', "{$pengguna->email} ({$pengguna->role})");

        return redirect()->route('panel.pengguna.index')->with('sukses', 'Akun diperbarui.');
    }

    public function destroy(User $pengguna): RedirectResponse
    {
        if ($pengguna->is(Auth::guard('user')->user())) {
            return back()->with('galat', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }
        if ($pengguna->isAdmin() && $this->adminTerakhir($pengguna)) {
            return back()->with('galat', 'Ini satu-satunya Admin; tidak boleh dihapus.');
        }
        // Jejak keputusan merujuk ke akun ini; jangan dihapus agar riwayat tetap utuh.
        if (PengajuanIzin::query()->where('diputuskan_oleh', $pengguna->getKey())->exists()
            || Presensi::query()->where('lembur_diputuskan_oleh', $pengguna->getKey())->exists()) {
            return back()->with('galat', 'Akun ini tercatat memutuskan pengajuan/lembur sehingga tidak dihapus. Ubah kata sandi atau role-nya bila perlu.');
        }

        $pengguna->delete();
        LogAktivitas::catat('pengguna.hapus', "{$pengguna->email} ({$pengguna->role})");

        return back()->with('sukses', 'Akun dihapus.');
    }

    private function adminTerakhir(User $pengguna): bool
    {
        return ! User::query()->where('role', User::ROLE_ADMIN)->whereKeyNot($pengguna->getKey())->exists();
    }
}
