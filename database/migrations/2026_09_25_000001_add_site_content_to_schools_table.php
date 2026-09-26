<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public website:
 *  - landing_layout: which public site design the school uses.
 *      1 = classic single-page landing (PublicLandingPage)
 *      2 = multi-page school website (SchoolWebsite)
 *  - site_content: structured website content (identity, affiliation,
 *    academics, facilities, admissions, gallery, team, mandatory
 *    disclosure, SEO …) rendered by layout 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->unsignedTinyInteger('landing_layout')->default(1)->after('landing_theme_config');
            $table->json('site_content')->nullable()->after('landing_layout');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['landing_layout', 'site_content']);
        });
    }
};
