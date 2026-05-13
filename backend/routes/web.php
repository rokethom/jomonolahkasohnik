<?php

use Illuminate\Http\Request;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');

Route::get('/admin/login', function () {
    if (Auth::check()) {
        return redirect('/admin');
    }

    return view('auth.filament-fallback-login');
})->name('filament.admin.auth.login');

Route::post('/admin/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $remember = $request->boolean('remember');

    if (! Auth::attempt($credentials, $remember)) {
        throw ValidationException::withMessages([
            'email' => 'Email atau password tidak sesuai.',
        ]);
    }

    $user = $request->user();
    $panel = Filament::getPanel('admin');

    if (! $user || ! $user->canAccessPanel($panel)) {
        Auth::logout();

        throw ValidationException::withMessages([
            'email' => 'Akun ini tidak memiliki akses backend.',
        ]);
    }

    $request->session()->regenerate();

    return redirect()->intended('/admin');
});
