<?php
use Illuminate\Support\Facades\Route;
// No AMF, registration, reward, chat or game endpoints exposed in MB01.
Route::get('/', fn () => redirect('/admin'));
