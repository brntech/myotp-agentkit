<?php

use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VerificationController::class, 'phoneForm'])->name('phone.form');
Route::post('/send', [VerificationController::class, 'sendOtp'])->name('phone.send');
Route::get('/verify', [VerificationController::class, 'verifyForm'])->name('verify.form');
Route::post('/verify', [VerificationController::class, 'verifyOtp'])->name('verify.submit');
Route::get('/success', [VerificationController::class, 'success'])->name('verify.success');
