<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('link_associations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')
                  ->constrained('links')
                  ->cascadeOnDelete();
            $table->foreignId('associated_link_id')
                  ->constrained('links')
                  ->cascadeOnDelete();
            $table->unique(['link_id', 'associated_link_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_associations');
    }
};
