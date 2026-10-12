<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subject ownership and section membership.
     *
     * One nullable column on each side encodes the whole rule:
     *
     *   subjects.department_id IS NULL  → minor / shared: visible to every department
     *   subjects.department_id IS SET   → major: owned by exactly that department
     *   sections.department_id          → the department the section belongs to
     *
     * A subject may be attached to a section when the subject is shared, or when
     * the two departments are the same. Because "a major with no owner" is
     * unrepresentable in this shape, the rule introduces no new invalid state for
     * validation to defend against.
     *
     * Nullable on both sides is deliberate:
     *  - `nullOnDelete` keeps subjects and sections valid if a department is ever
     *    removed, rather than cascading a deletion into scheduling data.
     *  - Existing rows keep working before the ownership seeder runs: every
     *    current subject reads as shared, so nothing already attached to a
     *    section becomes invalid. That is what lets this ship without touching a
     *    single schedule or section_subjects row.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('is_active')
                ->constrained()->nullOnDelete();
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('student_count')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
