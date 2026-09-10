<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AppController::class, 'page']);
Route::post('/api/login', [AppController::class, 'login']);
Route::post('/api/logout', [AppController::class, 'logout']);
Route::match(['GET', 'POST'], '/api/data', [AppController::class, 'data']);
Route::post('/api/permanent-note-attachments', [AppController::class, 'uploadAttachments']);
Route::get('/api/permanent-note-attachments/{id}', [AppController::class, 'downloadAttachment'])->whereNumber('id');
