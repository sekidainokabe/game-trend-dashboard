<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ユーザーの無効化（docs/SPEC.md 8章）。
 * 行を消さずに日時で印を付ける。過去の変更セットや操作履歴の作成者として残すため
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
