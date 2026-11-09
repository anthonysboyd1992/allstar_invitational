<?php

use App\Http\Controllers\EntryListPrintController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LineupPrintController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\ResultsPrintController;
use App\Http\Controllers\TimingPullerDownloadController;
use App\Http\Controllers\TransponderExportController;
use App\Http\Controllers\VmixFeedController;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'pages::login')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::middleware('can:access-competitors')->group(function () {
        Route::livewire('/competitors', 'pages::competitors')->name('competitors');
        Route::get('/competitors/print', EntryListPrintController::class)->name('competitors.print');
        Route::get('/competitors/print/announcer', EntryListPrintController::class)->name('competitors.print-announcer');
    });
    Route::livewire('/cars', 'pages::cars')->middleware('can:access-cars')->name('cars');
    Route::middleware('can:access-lineups')->group(function () {
        Route::livewire('/lineups', 'pages::lineups')->name('lineups');
        Route::get('/lineups/print', LineupPrintController::class)->name('lineups.print');
    });
    Route::middleware('can:access-results')->group(function () {
        Route::livewire('/results', 'pages::results')->name('results');
        Route::get('/results/print', ResultsPrintController::class)->name('results.print');
    });
    Route::livewire('/vmix', 'pages::vmix')->middleware('can:access-vmix')->name('vmix');
    Route::livewire('/vmix-data', 'pages::vmix-data')->middleware('can:access-vmix-data')->name('vmix-data');
    Route::livewire('/settings', 'pages::settings')->middleware('can:access-settings')->name('settings');
    Route::livewire('/users', 'pages::users')->middleware('can:access-users')->name('users');

    Route::middleware('can:access-timing')->group(function () {
        Route::livewire('/timing', 'pages::timing')->name('timing');
        Route::get('/exports/races/{race}/transponders.csv', [TransponderExportController::class, 'transponders'])->name('exports.transponders');
        Route::get('/exports/races/{race}/laps.dat', [TransponderExportController::class, 'laps'])->name('exports.laps');
        Route::get('/exports/races/{race}/timing.zip', [TransponderExportController::class, 'raceZip'])->name('exports.race-zip');
        Route::get('/exports/types/{raceType}/transponders.csv', [TransponderExportController::class, 'typeTransponders'])->name('exports.type-transponders');
        Route::get('/exports/types/{raceType}/laps.dat', [TransponderExportController::class, 'typeLaps'])->name('exports.type-laps');
        Route::get('/exports/types/{raceType}/timing.zip', [TransponderExportController::class, 'typeZip'])->name('exports.type-zip');
        Route::get('/exports/timing.zip', [TransponderExportController::class, 'bundle'])->name('exports.timing-zip');
        Route::get('/exports/timing-puller.zip', TimingPullerDownloadController::class)->name('exports.timing-puller');
    });

    Route::post('/logout', LogoutController::class)->name('logout');
});

Route::get('/vmix/lineup.json', [VmixFeedController::class, 'lineup'])->name('feeds.lineup');
Route::get('/vmix/driver.json', [VmixFeedController::class, 'driver'])->name('feeds.driver');
Route::get('/vmix/data.json', [VmixFeedController::class, 'data'])->name('feeds.data');
Route::get('/vmix/clear.png', [VmixFeedController::class, 'clear'])->name('feeds.clear');
Route::get('/timing/lineups.json', [TransponderExportController::class, 'lineups'])->name('feeds.timing-lineups');
