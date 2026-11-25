<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// 閲覧はログインなしでできる（URL を開いた人がすぐ中身を見られるように）。データは毎時しか変わらないのでキャッシュする
Route::get('/dashboard', DashboardController::class);
Route::get('/ranking', RankingController::class);
Route::get('/genres', GenreController::class);
Route::get('/ratings', RatingController::class);
Route::get('/releases', ReleaseController::class);
Route::get('/games/{game}', [GameController::class, 'show']);
Route::get('/games/{game}/players', [GameController::class, 'players']);

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// active：ログイン中に無効化されたユーザーを締め出す（app/Http/Middleware/EnsureUserIsActive.php）
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/password', [AuthController::class, 'updatePassword']);

    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);
});
