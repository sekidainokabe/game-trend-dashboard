<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 毎時の「今遊んでいる人数」。いちばん大きくなる表（200本 × 24回 × 90日 ≒ 43万行を保つ）。
        // id を持たず、(ゲーム, 時刻) を主キーにする。同じ時刻を2回取っても1行のまま（取り直しは上書き）にするため
        Schema::create('player_snapshots', function (Blueprint $table) {
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            // 時刻は1時間単位に切りそろえる（UTC）
            $table->timestamp('captured_at');
            $table->unsignedInteger('players');

            // 主キーの索引で「あるゲームの期間の推移」を速く引ける
            $table->primary(['game_id', 'captured_at']);
            // 「最新の時刻の全ゲーム」（ランキング）と、古い行の間引きに使う
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_snapshots');
    }
};
