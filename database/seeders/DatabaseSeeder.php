<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Order matters: EmailTemplateSeeder is idempotent (updateOrCreate by
        // slug + null school_id), so it's safe to run on every `db:seed`. It
        // must run on a fresh install so the Email Branding studio in the
        // admin panel has something to fall back to before any school has
        // customized their templates.
        $this->call(EmailTemplateSeeder::class);
        // Plans is idempotent (updateOrCreate by slug) — runs cleanly on
        // every db:seed and also gets the public marketing-site catalog
        // populated on fresh installs without manual SQL.
        $this->call(PlanSeeder::class);
        $this->call(SchoolDemoSeeder::class);
    }
}
