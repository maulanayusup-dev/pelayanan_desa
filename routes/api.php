<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ReportAttachmentController;
use App\Http\Controllers\SystemReportController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Chatbot API Endpoint (Handles both authenticated Sanctum token & guest requests)
Route::post('/chat', [ChatbotController::class, 'chat']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('active.user')->group(function () {
        Route::get('/user', [AuthController::class, 'me']);

        // General application routes
        Route::get('/applications', [ApplicationController::class, 'index']);
        Route::post('/applications', [ApplicationController::class, 'store']);
        Route::get('/applications/{application}', [ApplicationController::class, 'show']);

        // Staf routes
        Route::middleware('role:staf')->group(function () {
            Route::post('/applications/{application}/staff-review', [ApplicationController::class, 'staffReview']);
        });

        // Supervisor routes
        Route::middleware('role:supervisor')->group(function () {
            Route::post('/applications/{application}/supervisor-review', [ApplicationController::class, 'supervisorReview']);
        });

        // Kades routes
        Route::middleware('role:kades')->group(function () {
            Route::post('/applications/{application}/kades-approve', [ApplicationController::class, 'kadesApprove']);
        });

        // Sistem Laporan & Keluhan (Fondasi backend & otorisasi)
        Route::get('/reports/attachments/{attachment}', ReportAttachmentController::class)
            ->whereUuid('attachment')
            ->name('api.reports.attachments.download');
        Route::get('/reports', [SystemReportController::class, 'index'])->name('api.reports.index');
        Route::post('/reports', [SystemReportController::class, 'store'])->name('api.reports.store');
        Route::get('/reports/{report}', [SystemReportController::class, 'show'])
            ->whereUuid('report')
            ->name('api.reports.show');
        Route::post('/reports/{report}/messages', [SystemReportController::class, 'storeMessage'])
            ->whereUuid('report')
            ->name('api.reports.messages.store');
        Route::patch('/reports/{report}/assign', [SystemReportController::class, 'assign'])
            ->whereUuid('report')
            ->name('api.reports.assign');
        Route::patch('/reports/{report}/priority', [SystemReportController::class, 'updatePriority'])
            ->whereUuid('report')
            ->name('api.reports.priority.update');
        Route::patch('/reports/{report}/status', [SystemReportController::class, 'updateStatus'])
            ->whereUuid('report')
            ->name('api.reports.status.update');
    });
});
