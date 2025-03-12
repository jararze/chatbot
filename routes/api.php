<?php

use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/whatsapp', [WhatsappWebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsappWebhookController::class, 'handle']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
