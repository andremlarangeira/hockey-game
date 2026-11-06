<?php

use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/time')->name('settings.index');
    Route::redirect('settings/profile', '/time')->name('profile.edit');
    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::put('settings/password', [SecurityController::class, 'update'])->name('user-password.update');
});
