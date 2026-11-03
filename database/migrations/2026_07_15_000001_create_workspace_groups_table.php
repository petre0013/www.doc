<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // A workspace belongs to at most one group. Null = ungrouped (top level),
        // which is how every existing workspace reads. nullOnDelete is only a DB
        // backstop; the app-level "deleting a group reverts its workspaces to
        // ungrouped" behavior lands in M2.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('slug')
                ->constrained('workspace_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::dropIfExists('workspace_groups');
    }
};
