<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LineupPrintController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\TransponderExportController;
use App\Http\Controllers\VmixFeedController;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'pages::login')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::livewire('/competitors', 'pages::competitors')->middleware('can:access-competitors')->name('competitors');
    Route::livewire('/cars', 'pages::cars')->middleware('can:access-cars')->name('cars');
    Route::middleware('can:access-lineups')->group(function () {
        Route::livewire('/lineups', 'pages::lineups')->name('lineups');
        Route::get('/lineups/print', LineupPrintController::class)->name('lineups.print');
    });
    Route::livewire('/vmix', 'pages::vmix')->middleware('can:access-vmix')->name('vmix');
    Route::livewire('/vmix-data', 'pages::vmix-data')->middleware('can:access-vmix-data')->name('vmix-data');
    Route::livewire('/settings', 'pages::settings')->middleware('can:access-settings')->name('settings');
    Route::livewire('/users', 'pages::users')->middleware('can:access-users')->name('users');

    Route::middleware('can:access-timing')->group(function () {
        Route::livewire('/timing', 'pages::timing')->name('timing');
        Route::get('/exports/races/{race}/transponders.csv', [TransponderExportController::class, 'transponders'])->name('exports.transponders');
        Route::get('/exports/races/{race}/laps.dat', [TransponderExportController::class, 'laps'])->name('exports.laps');
    });

    Route::post('/logout', LogoutController::class)->name('logout');
});

Route::get('/vmix/lineup.json', [VmixFeedController::class, 'lineup'])->name('feeds.lineup');
Route::get('/vmix/driver.json', [VmixFeedController::class, 'driver'])->name('feeds.driver');
Route::get('/vmix/data.json', [VmixFeedController::class, 'data'])->name('feeds.data');
Route::get('/vmix/clear.png', [VmixFeedController::class, 'clear'])->name('feeds.clear');
