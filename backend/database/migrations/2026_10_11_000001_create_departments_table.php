<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Academic departments — the owning unit for major subjects.
     *
     * The system previously had no department concept at all: no table, no
     * column, and no relationship anywhere in the schema, so "a major subject
     * belongs to exactly one department" could not be expressed, let alone
     * enforced. This table makes that ownership real and foreign-key
     * constrained.
     *
     * `code` is the stable handle a seeder or an import keys on (BIT, ELEC),
     * while `name` is what a screen shows. The unique index means re-running the
     * ownership seeder converges on the same rows instead of duplicating them.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
