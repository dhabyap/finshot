<?php

use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::post('/transfers', [TransferController::class, 'store']);
Route::get('/transfers/{transferId}', [TransferController::class, 'show']);
Route::post('/transfers/{transferId}/cancel', [TransferController::class, 'cancel']);
