<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * subjects.classification — the reviewed status of every subject.
     *
     * Before this column, ownership was encoded by `department_id` alone, and a
     * null meant two different things that the schema could not tell apart:
     * "deliberately shared with every department" and "nobody has reviewed this
     * yet". Every null-ownership subject was therefore treated as shared, so a
     * freshly imported subject that no one had classified was silently
     * schedulable by everyone — the failure mode the preflight could only warn
     * about.
     *
     * This column separates the two states:
     *
     *   classification = 'major'         a reviewed major; must carry a
     *                                    department_id (the owning department)
     *   classification = 'minor'         a reviewed shared / service subject;
     *                                    must NOT carry a department_id, and is
     *                                    open to every department
     *   classification = 'unclassified'  not reviewed yet; schedulable nowhere
     *                                    and rejected by generation
     *
     * `unclassified` is the default, never `minor`: an unmapped or newly created
     * subject must be indeterminate until someone actually classifies it, which
     * is the whole point of the column. The default is deliberately the state
     * that grants nothing.
     *
     * The three invariants below are enforced by the database, not only by the
     * application, so no importer, seeder or manual psql session can produce a
     * major with no owner or a minor that claims one:
     *
     *   1. classification is one of the three legal values;
     *   2. classification = 'major' requires department_id;
     *   3. classification = 'minor' requires department_id IS NULL.
     *
     * Which departments may schedule a given major — including a major shared
     * across departments — is held in `subject_department_access` (see the next
     * migration), not in this column.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('classification', 16)
                ->default('unclassified')
                ->after('department_id');
        });

        // ALTER TABLE ... ADD CONSTRAINT CHECK is not supported by every driver
        // (SQLite has no such statement), so the constraints are added where the
        // driver supports them and the application-level rule carries the rest.
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true)) {
            DB::statement(
                "ALTER TABLE subjects ADD CONSTRAINT subjects_classification_check "
                ."CHECK (classification IN ('major', 'minor', 'unclassified'))"
            );

            DB::statement(
                'ALTER TABLE subjects ADD CONSTRAINT subjects_major_requires_owner_check '
                ."CHECK (classification <> 'major' OR department_id IS NOT NULL)"
            );

            DB::statement(
                'ALTER TABLE subjects ADD CONSTRAINT subjects_minor_has_no_owner_check '
                ."CHECK (classification <> 'minor' OR department_id IS NULL)"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true)) {
            foreach ([
                'subjects_classification_check',
                'subjects_major_requires_owner_check',
                'subjects_minor_has_no_owner_check',
            ] as $constraint) {
                DB::statement("ALTER TABLE subjects DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }
};
