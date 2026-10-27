<?php
use Illuminate\Support\Facades\Route;
// Legacy AMF, registration, chat and battle endpoints remain closed.
Route::get('/', fn () => redirect('/admin'));
require __DIR__.'/evaluation.php';
