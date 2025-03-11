<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DropboxController;
use App\Http\Controllers\Api\GoogleCloudStorageController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Dropbox Routes
Route::prefix('dropbox')->group(function () {
    Route::get('test', [DropboxController::class, 'test']);
    Route::get('list', [DropboxController::class, 'listContents']);
    Route::post('copy', [DropboxController::class, 'copy']);
    Route::post('upload', [DropboxController::class, 'upload']);
    Route::delete('delete', [DropboxController::class, 'delete']);
    Route::post('batch-delete', [DropboxController::class, 'delete']); // Alias for delete with more descriptive name
    Route::get('duplicates', [DropboxController::class, 'findDuplicates']);
});

// Google Cloud Storage Routes
Route::prefix('gcs')->group(function () {
    Route::get('list', [GoogleCloudStorageController::class, 'listContents']);
    Route::post('copy', [GoogleCloudStorageController::class, 'copy']);
    Route::post('upload', [GoogleCloudStorageController::class, 'upload']);
    Route::delete('delete', [GoogleCloudStorageController::class, 'delete']);
    Route::post('batch-delete', [GoogleCloudStorageController::class, 'delete']); // Alias for delete with more descriptive name
    Route::get('duplicates', [GoogleCloudStorageController::class, 'findDuplicates']);
});
