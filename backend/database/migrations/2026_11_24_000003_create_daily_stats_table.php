<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1日ごとの集計（日付は日本時間）。グラフはこちらを読む（毎時の生データを毎回集計しないため）。
        // 毎時の生データは90日で消すが、こちらはずっと残す（200本 × 365日 ≒ 7万行/年）
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // 人数は毎時の取得から、価格・評価は毎日の詳細の取得から、別々に書き込む。片方しかない日もあるので null を許す
            $table->unsignedInteger('peak_players')->nullable();
            $table->unsignedInteger('avg_players')->nullable();
            // その日に何回取れたか（定期実行が抜けた日を見分けるため）
            $table->unsignedSmallInteger('samples')->default(0);
            $table->unsignedInteger('price_jpy')->nullable();
            $table->unsignedSmallInteger('discount_percent')->nullable();
            $table->unsignedInteger('recommendations')->nullable();
            $table->timestamps();

            $table->primary(['game_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
