<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('categorias', 'categorias')->name('categories.index');
    Route::view('contas', 'contas')->name('bills.index');
    Route::view('carteira', 'carteira')->name('wallet.index');
    Route::view('familia', 'familia')->name('family.index');
});

require __DIR__.'/settings.php';
