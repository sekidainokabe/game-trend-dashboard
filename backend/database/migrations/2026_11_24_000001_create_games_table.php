<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ゲーム。Steam と RAWG の両方から来るため、どちらの ID も持てるようにする（どちらか片方だけのこともある）
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('steam_appid')->nullable()->unique();
            $table->unsignedInteger('rawg_id')->nullable()->unique();
            $table->string('rawg_slug')->nullable();
            // Steam の人気上位から見つけた直後は、名前がまだない（詳細の取得で埋まる）
            $table->string('name')->nullable();
            // 画像は保存せず、API が返す URL をそのまま表示する
            $table->text('image_url')->nullable();
            $table->date('release_date')->nullable();
            $table->boolean('coming_soon')->default(false);
            $table->boolean('is_free')->default(false);
            // 価格は円（Steam は銭の単位で返すため 100 で割る）。最新の値だけをここに持ち、推移は daily_stats に残す
            $table->unsignedInteger('price_jpy')->nullable();
            $table->unsignedSmallInteger('discount_percent')->default(0);
            $table->unsignedSmallInteger('metacritic')->nullable();
            $table->decimal('rawg_rating', 3, 2)->nullable();
            // Steam のレビュー件数（好評率は API で取れないため、件数を人気の目安にする）
            $table->unsignedInteger('recommendations')->nullable();
            // 対応機種（RAWG）。絞り込みには使わず表示だけなので、別表にせず配列で持つ
            $table->jsonb('platforms')->nullable();
            // 毎時の人数を取る対象か（人気上位から外れても、推移を残すため追い続ける）
            $table->boolean('tracked')->default(true);
            $table->timestamp('details_fetched_at')->nullable();
            $table->timestamps();

            $table->index('release_date');
        });

        // ジャンル。Steam と RAWG で呼び方が違うため、共通のキー（action など）にそろえて持つ（config/genres.php）
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 50);
            $table->timestamps();
        });

        Schema::create('game_genre', function (Blueprint $table) {
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->primary(['game_id', 'genre_id']);
            // ジャンルからゲームを引く（ジャンル別の集計）ための索引
            $table->index('genre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_genre');
        Schema::dropIfExists('genres');
        Schema::dropIfExists('games');
    }
};
