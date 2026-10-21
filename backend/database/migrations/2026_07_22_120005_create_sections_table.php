<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('year_level');
            $table->string('academic_year');
            $table->string('semester_name');
            $table->json('preferred_days');
            $table->time('preferred_start_time');
            $table->time('preferred_end_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};