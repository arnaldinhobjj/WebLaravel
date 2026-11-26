<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A material's 2D CAD drawing (DXF), shown in its own view next to the 3D model.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('drawing_file_id')->nullable()->after('blueprint_file_id')->constrained('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('drawing_file_id');
        });
    }
};
