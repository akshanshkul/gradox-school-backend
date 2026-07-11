<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-(class × subject) lesson plan column.
 *
 * Stored as HTML (longText) because the Lesson Plan tab in the Class Subject
 * Studio is a rich-text editor: bold, italics, headings, lists, tables, etc.
 * Same column also holds the HTML extracted from any .docx file the teacher
 * uploads via mammoth.js. On the download side, the stored HTML is converted
 * back to a Word file in the browser (html-docx-js). longText comfortably
 * holds even chapter-length plans (~16MB cap, way more than any real plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_subject', function (Blueprint $table) {
            if (!Schema::hasColumn('class_subject', 'lesson_plan')) {
                $table->longText('lesson_plan')->nullable()->after('teacher_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_subject', function (Blueprint $table) {
            if (Schema::hasColumn('class_subject', 'lesson_plan')) {
                $table->dropColumn('lesson_plan');
            }
        });
    }
};
