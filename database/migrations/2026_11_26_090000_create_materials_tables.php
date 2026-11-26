<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materials and their test results: the specified composition with the
     * measured chemical analysis, tensile tests and Charpy impact tests. The
     * blueprint, photo and 3D model are files attached to the material.
     */
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('part_name')->nullable();
            $table->string('grade')->nullable();
            $table->string('heat_number')->nullable();
            $table->text('description')->nullable();
            $table->decimal('spec_min_yield_mpa', 8, 1)->nullable();
            $table->decimal('spec_min_uts_mpa', 8, 1)->nullable();
            $table->decimal('spec_max_uts_mpa', 8, 1)->nullable();
            $table->decimal('spec_min_elongation_pct', 5, 1)->nullable();
            $table->decimal('spec_min_charpy_avg_j', 8, 1)->nullable();
            $table->decimal('spec_min_charpy_single_j', 8, 1)->nullable();
            $table->decimal('charpy_temperature_c', 6, 1)->nullable();
            $table->foreignId('blueprint_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('image_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('model_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('material_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('element', 4);
            $table->decimal('spec_min_pct', 8, 4)->nullable();
            $table->decimal('spec_max_pct', 8, 4)->nullable();
            $table->decimal('measured_pct', 8, 4)->nullable();
            $table->timestamps();

            $table->unique(['material_id', 'element']);
        });

        Schema::create('material_tensile_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('specimen');
            $table->string('orientation')->nullable();
            $table->date('tested_at')->nullable();
            $table->decimal('yield_mpa', 8, 1);
            $table->decimal('uts_mpa', 8, 1);
            $table->decimal('elongation_pct', 5, 1);
            $table->decimal('reduction_of_area_pct', 5, 1)->nullable();
            $table->timestamps();
        });

        Schema::create('material_charpy_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('specimen');
            $table->string('notch', 8)->default('V');
            $table->decimal('temperature_c', 6, 1);
            $table->decimal('energy_j', 8, 1);
            $table->decimal('lateral_expansion_mm', 6, 2)->nullable();
            $table->decimal('shear_pct', 5, 1)->nullable();
            $table->date('tested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_charpy_tests');
        Schema::dropIfExists('material_tensile_tests');
        Schema::dropIfExists('material_elements');
        Schema::dropIfExists('materials');
    }
};
