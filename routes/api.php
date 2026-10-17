<?php

use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::post('/transfers', [TransferController::class, 'store']);
Route::post('/transfers/{transferId}/send', [TransferController::class, 'send']);
Route::get('/transfers/{transferId}', [TransferController::class, 'show']);
Route::post('/transfers/{transferId}/cancel', [TransferController::class, 'cancel']);
Route::post('/callbacks/partner', [TransferController::class, 'callback']);
