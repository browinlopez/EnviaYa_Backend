<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('prueba');
});

Route::get('/prueba', function () {
    return view('prueba');
});
