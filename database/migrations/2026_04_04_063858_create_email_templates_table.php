<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $col) {
            $col->id();
            $col->unsignedBigInteger('school_id')->nullable(); // Null for system global defaults
            $col->string('slug'); // e.g. admission_confirmation, staff_welcome
            $col->string('name')->nullable(); // Human readable name
            $col->string('subject');
            $col->longText('content_html');
            $col->json('placeholders')->nullable(); // Available tags for UI help
            $col->boolean('is_system')->default(false);
            $col->timestamps();

            $col->unique(['school_id', 'slug']);
            $col->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
