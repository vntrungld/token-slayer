<?php

use App\Http\Controllers\Auth\SlackController;
use App\Http\Controllers\AvatarProxyController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\SlayerWheelController;
use App\Services\GitHub\ReleaseResolver;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('battlefield');
    }

    return view('welcome');
});

Route::get('/auth/slack', [SlackController::class, 'redirect'])->name('slack.login');
Route::get('/auth/slack/callback', [SlackController::class, 'callback']);

Route::get('/profile', fn () => view('profile'))->middleware('auth')->name('profile');

Route::get('/guide', fn () => view('guide', [
    'namespace' => config('app.hook_namespace'),
]))->middleware('auth')->name('guide');

Route::get('/setup', fn () => view('setup'))->middleware('auth')->name('setup');

Route::get('/admin-token', function () {
    abort_unless(auth()->user()->roles()->exists(), 403);

    return view('admin-token');
})->middleware('auth')->name('admin-token');

Route::get('/admin/usage', fn () => view('admin-usage'))
    ->middleware(['auth', 'can:view_usage_analytics'])
    ->name('admin.usage');

// The Filament panel moved from /admin to the friendlier /dashboard. Keep the
// old URLs alive (bookmarks, Slack notification deep links) — registered after
// /admin/usage so that route still wins.
Route::get('/admin/{path?}', function (?string $path = null) {
    $query = request()->getQueryString();

    return redirect('/dashboard'.($path === null ? '' : '/'.$path).($query === null ? '' : '?'.$query));
})->where('path', '.*');

// clientVersion comes from ReleaseResolver directly, NOT the cache: the version
// stamped into the served script must match the artifact being served.
Route::get('/install', fn (ReleaseResolver $resolver) => response(
    view('install-script', [
        'baseUrl' => url('/api/events'),
        'apiBase' => url('/'),
        'namespace' => config('app.hook_namespace'),
        'clientVersion' => $resolver->latest()['version'] ?? '',
        'hookVersion' => config('token_slayer.hook_version'),
        'installUrl' => route('install-script'),
        'slayerWheelUrl' => route('slayer-wheel'),
    ])->render(),
    200,
    ['Content-Type' => 'text/x-shellscript; charset=utf-8'],
))->name('install-script');

// Native-Windows PowerShell installer. Mirrors /install; `installUrl` points at
// itself so `token-slayer update` on Windows re-fetches the PowerShell script.
Route::get('/install.ps1', fn (ReleaseResolver $resolver) => response(
    view('install-script-ps1', [
        'baseUrl' => url('/api/events'),
        'namespace' => config('app.hook_namespace'),
        'clientVersion' => $resolver->latest()['version'] ?? '',
        'hookVersion' => config('token_slayer.hook_version'),
        'installUrl' => route('install-script-ps1'),
        'slayerWheelUrl' => route('slayer-wheel'),
    ])->render(),
    200,
    ['Content-Type' => 'text/plain; charset=utf-8'],
))->name('install-script-ps1');

Route::get('/dist/slayer_cli-latest.whl', SlayerWheelController::class)
    ->middleware('hook.token')
    ->name('slayer-wheel');

Route::get('/install-cowork', fn () => response(
    view('cowork-install-script', [
        'baseUrl' => url('/api/events').'?provider=cowork',
        'namespace' => config('app.hook_namespace'),
        'watcherUrl' => route('cowork-watcher'),
    ])->render(),
    200,
    ['Content-Type' => 'text/x-shellscript; charset=utf-8'],
))->name('cowork-install-script');

Route::get('/cowork-watcher.py', fn () => response(
    view('cowork-watcher', [
        'eventsUrl' => url('/api/events').'?provider=cowork',
        'namespace' => config('app.hook_namespace'),
    ])->render(),
    200,
    ['Content-Type' => 'text/x-python; charset=utf-8'],
))->name('cowork-watcher');

Route::get('/tracker.user.js', fn () => response(
    view('userscript', [
        'eventsUrl' => url('/api/events').'?provider=claude-ai',
        'appUrl' => url('/'),
        'appHost' => parse_url(url('/'), PHP_URL_HOST),
    ])->render(),
    200,
    ['Content-Type' => 'text/javascript; charset=utf-8'],
))->name('userscript');

Route::get('/battlefield', fn () => view('battlefield'))->name('battlefield');

Route::get('/avatars/{user}', AvatarProxyController::class)->name('avatar');

Route::get('/history', [HistoryController::class, 'index'])->name('history');
