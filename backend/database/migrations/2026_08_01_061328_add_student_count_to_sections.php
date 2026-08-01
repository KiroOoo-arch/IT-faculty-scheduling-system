<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up()
{
    Schema::table('sections', function (Blueprint $table) {
        $table->integer('student_count')->default(30)->after('preferred_end_time');
    });
}

public function down()
{
    Schema::table('sections', function (Blueprint $table) {
        $table->dropColumn('student_count');
    });
}

};
