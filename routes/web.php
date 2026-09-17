<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\BcDocumentController;
use App\Http\Controllers\BcImageController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentAttachmentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SalesOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');

    Route::get('/login/google', [GoogleController::class, 'redirect']);

    Route::get('/auth/google/callback', [GoogleController::class, 'callback']);
});

/*
|--------------------------------------------------------------------------
| Private Dashboard
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::redirect('/', '/dashboard')->name('home');

    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::get('/products', [ProductController::class, 'index'])
        ->name('products.index');

    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->name('products.show');

    Route::get('/promotion', [PromotionController::class, 'index'])
        ->name('promotions.index');

    Route::get('/promotion/{campaign}', [PromotionController::class, 'show'])
        ->name('promotions.show');

    Route::get('/customer', [CustomerController::class, 'index'])
        ->name('customers.index');

    Route::get('/customer/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show');

    Route::get('/contact', [ContactController::class, 'index'])
        ->name('contacts.index');

    Route::get('/contact/{contact}', [ContactController::class, 'show'])
        ->name('contacts.show');

    Route::get('/sales-order', [SalesOrderController::class, 'index'])
        ->name('sales-orders.index');

    Route::get('/sales-order/{salesOrder}', [SalesOrderController::class, 'show'])
        ->name('sales-orders.show');

    Route::get('/sales-invoice', [SalesInvoiceController::class, 'index'])
        ->name('sales-invoices.index');

    Route::get('/sales-invoice/{salesInvoice}', [SalesInvoiceController::class, 'show'])
        ->name('sales-invoices.show');

    // List only. An attachment has no detail page: it carries a filename and a
    // parent, and it is delivered as a field of that parent's payload, so the
    // row links to the parent rather than to a page of its own.
    Route::get('/document-attachment', [DocumentAttachmentController::class, 'index'])
        ->name('document-attachments.index');
});

/*
|--------------------------------------------------------------------------
| Business Central Signed Routes
|--------------------------------------------------------------------------
*/

Route::middleware('bc.signed')->group(function () {

    Route::get('/bc-image/{itemId}', [BcImageController::class, 'item']);

    Route::get('/bc-customer-image/{customerId}', [BcImageController::class, 'customer']);

    Route::get('/bc-salesperson-image/{id}', [BcImageController::class, 'salesperson']);

    Route::get('/bc-doc/{attachmentId}', [BcDocumentController::class, 'attachment']);

    Route::get('/bc-sales-invoice-pdf/{invoiceId}', [BcDocumentController::class, 'salesInvoicePdf']);

    Route::get('/bc-credit-memo-pdf/{creditMemoId}', [BcDocumentController::class, 'creditMemoPdf']);
});
