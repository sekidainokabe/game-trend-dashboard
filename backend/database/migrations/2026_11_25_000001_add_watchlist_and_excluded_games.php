<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 管理者が「集計から外す」と決めたゲーム（ゲームではないソフトなど）。
        // tracked は取得処理が毎日上書きするため、人の判断は別の列に持つ
        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('excluded_at')->nullable();
        });

        // ログインした人が気になるゲームを登録しておく
        Schema::create('watchlist', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist');
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('excluded_at');
        });
    }
};
