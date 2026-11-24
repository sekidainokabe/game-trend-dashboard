<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 操作履歴の「期間」「操作」での絞り込み用の索引（docs/scale.md）。
 *
 * 操作履歴は消さずに増え続ける。4年分（30万件）で測ると、索引がないときは
 * 古い1週間を見るのに約 0.45 秒（一覧 + 件数）、操作での件数に約 0.12 秒かかっていた
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at');
            // InnoDB の索引には主キー（id）も入っているので、「操作で絞って id の新しい順」も索引だけで並べられる
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['action']);
        });
    }
};
