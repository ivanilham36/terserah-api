<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_option_id')->nullable()->constrained('options')->nullOnDelete();
            $table->string('name');
            $table->string('category');
            $table->string('code', 8)->unique();
            $table->boolean('use_location')->default(false);
            $table->string('status')->default('open'); // open | decided
            $table->text('decision_reason')->nullable();
            $table->json('weights')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
