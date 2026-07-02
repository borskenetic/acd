<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zendy_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
            $table->dropIndex(['actor_user_id', 'created_at']);
            $table->dropColumn('actor_user_id');
        });

        Schema::table('zendy_logs', function (Blueprint $table) {
            $table->foreignId('zendy_user_id')->nullable()->after('id')->constrained('zendy_users')->nullOnDelete();
            $table->index(['zendy_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('zendy_logs', function (Blueprint $table) {
            $table->dropForeign(['zendy_user_id']);
            $table->dropIndex(['zendy_user_id', 'created_at']);
            $table->dropColumn('zendy_user_id');
        });

        Schema::table('zendy_logs', function (Blueprint $table) {
            $table->foreignId('actor_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->index(['actor_user_id', 'created_at']);
        });
    }
};
