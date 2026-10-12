<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * subject_department_access — which departments may schedule a given major.
     *
     * `subjects.department_id` stays the single owning department of a major: it
     * is who the subject belongs to, and the one department that always has
     * access to it. This table records the *authorization* separately, which lets
     * the two differ where the real curriculum requires it. The motivating case
     * is ELEC201: owned by Electronics, but taken by the BIT sections, so it is
     * owned by ELEC and authorized for both ELEC and BIT.
     *
     * A row means "this department may schedule this subject into its sections".
     * The owner's row is mandatory, not implied — enforcement reads this table
     * alone, so a major whose owner row is missing is a major no one can
     * schedule, which is exactly what the preflight reports.
     *
     * Minor / shared subjects have no rows here: being open to everyone is
     * expressed by `classification = 'minor'`, not by listing every department.
     *
     * Shape:
     *  - the composite primary key (subject_id, department_id) makes the pairing
     *    unique, so a repeated seeding converges instead of duplicating, and it
     *    indexes the (subject, department) lookups enforcement performs;
     *  - department_id carries its own index, because the composite key serves
     *    subject-first lookups only and the reverse direction ("what may this
     *    department schedule?") is just as common;
     *  - both foreign keys cascade on delete: this table holds authorization,
     *    never scheduling data, so removing a subject or a department should
     *    remove the grants that pointed at it rather than block the deletion.
     */
    public function up(): void
    {
        Schema::create('subject_department_access', function (Blueprint $table) {
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();

            $table->primary(['subject_id', 'department_id']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_department_access');
    }
};
