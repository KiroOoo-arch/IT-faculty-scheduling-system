<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Institutional, admin-editable settings.
     *
     * A key/value table rather than a column per setting: these are policy
     * values (the midday break) that the Department Head owns, not per-section
     * data, and new ones should not need a migration. The AI engine reads the
     * same table directly over its read-only connection.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};