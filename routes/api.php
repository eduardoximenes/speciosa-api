<?php

use App\Http\Controllers\LeadNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware('demo.auth')->group(function (): void {
    Route::post('/leads/{lead}/notes', [LeadNoteController::class, 'store']);
});
