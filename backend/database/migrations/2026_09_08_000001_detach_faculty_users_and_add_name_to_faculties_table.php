<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes faculty login accounts:
 * - faculties.user_id becomes nullable (faculty are records, not users)
 * - faculties.name stores the faculty member's name directly
 * - existing faculty names are copied from their linked user accounts,
 *   then all faculty-role user accounts are deleted (admin accounts untouched)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Schema changes
        Schema::table('faculties', function (Blueprint $table) {
            $table->string('name')->nullable()->after('user_id');
            $table->foreignId('user_id')->nullable()->change();
        });

        // 2. Data migration: copy the name from each linked user, then detach
        $faculties = DB::table('faculties')
            ->join('users', 'users.id', '=', 'faculties.user_id')
            ->whereNotNull('faculties.user_id')
            ->get(['faculties.id', 'users.name']);

        foreach ($faculties as $faculty) {
            DB::table('faculties')
                ->where('id', $faculty->id)
                ->update(['name' => $faculty->name, 'user_id' => null]);
        }

        // 3. Delete faculty login accounts (no longer referenced; admins untouched)
        DB::table('users')->where('role', 'faculty')->delete();
    }

    public function down(): void
    {
        // Recreate a login account for every faculty record that lost one,
        // so a rollback leaves the system in a working state.
        $faculties = DB::table('faculties')->whereNull('user_id')->get();

        foreach ($faculties as $faculty) {
            $userId = DB::table('users')->insertGetId([
                'name' => $faculty->name ?? 'Faculty',
                'email' => 'faculty' . $faculty->id . '@example.com',
                'password' => bcrypt('password'),
                'role' => 'faculty',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('faculties')->where('id', $faculty->id)->update(['user_id' => $userId]);
        }

        Schema::table('faculties', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
