<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 取得処理の実行記録。管理画面で「いつ・何件・成功したか」を見るためと、
        // 1日1回の処理を「前回の成功から24時間たったか」で動かすために使う
        Schema::create('fetch_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job', 50);
            // running / success / partial（一部のゲームだけ失敗） / failed
            $table->string('status', 20);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['job', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fetch_runs');
    }
};
