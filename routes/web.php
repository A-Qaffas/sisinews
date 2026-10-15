<?php

use App\Http\Controllers\Site\ArticleController;
use App\Http\Controllers\Site\CategoryController;
use App\Http\Controllers\Site\FeedController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LiveController;
use App\Http\Controllers\Site\NewsletterController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\RootRedirectController;
use App\Http\Controllers\Site\SearchController;
use App\Http\Controllers\Site\SitemapController;
use App\Models\Page;
use Illuminate\Support\Facades\Route;

// Public site. No reader accounts in v1: the only login is the staff panel at /admin.
// Every public page lives under /ar or /en; "/" just picks one.
Route::get('/', RootRedirectController::class)->name('root');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::post('/newsletter', [NewsletterController::class, 'subscribe'])
    ->middleware('throttle:newsletter')
    ->name('newsletter.subscribe');
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->name('newsletter.unsubscribe');
Route::post('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'confirmUnsubscribe'])
    ->middleware('throttle:newsletter')
    ->where('token', '[A-Za-z0-9]{40}')
    ->name('newsletter.unsubscribe.confirm');

Route::prefix('{locale}')
    ->where(['locale' => 'ar|en'])
    ->middleware('locale')
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/category/{slug}', CategoryController::class)->name('category');
        // {idSlug} = "12-some-headline". The numeric id is the identity; the slug is cosmetic.
        Route::get('/news/{idSlug}', ArticleController::class)
            ->where('idSlug', '[0-9]+(?:-.*)?')
            ->name('article');
        // Search scans article bodies with LIKE: cap it per IP so one client cannot pin the shared CPU.
        Route::get('/search', SearchController::class)->middleware('throttle:30,1')->name('search');
        Route::get('/feed.xml', FeedController::class)->name('feed');
        // Static pages (About, Privacy...). The slug shape is the one the staff form enforces.
        Route::get('/page/{slug}', PageController::class)->where('slug', Page::SLUG_PATTERN)->name('page');
        // Embedded YouTube/Vimeo broadcasts by governorate; optional ?g=<governorate slug>.
        Route::get('/live', LiveController::class)->name('live');
    });
