<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional need-to-know access: a restricted workspace is visible only to its
 * members and to admins. Defaults to unrestricted, so upgrades change nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('restricted')->default(false);
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_user');
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('restricted'));
    }
};
