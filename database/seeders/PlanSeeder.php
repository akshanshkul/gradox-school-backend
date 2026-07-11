<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Public pricing tiers shown on the marketing site and offered to schools
 * on signup. Idempotent (updateOrCreate keyed on slug) so re-running the
 * seeder updates pricing instead of duplicating rows.
 *
 * To update prices later: edit the rows here and re-run
 *   `php artisan db:seed --class=PlanSeeder`
 * Existing schools keep their assigned plan; only the catalog row changes.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free-trial',
                'name' => 'Free Trial',
                'price' => 0,
                'currency' => 'INR',
                'billing_cycle' => 'trial', // 30-day window enforced at signup
                'max_students' => 100,
                'max_users' => null,
                'description' => 'Try all features for free. No credit card required.',
                'features' => [
                    'Access to all features',
                    'Up to 100 students',
                    'WhatsApp Support',
                    'Free Onboarding',
                ],
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'slug' => 'basic',
                'name' => 'Basic',
                'price' => 999,
                'currency' => 'INR',
                'billing_cycle' => 'monthly',
                'max_students' => 300,
                'max_users' => null,
                'description' => 'Perfect for small schools getting started.',
                'features' => [
                    'Up to 300 students',
                    'Attendance Management',
                    'Fee Collection & Receipts',
                    'Parent SMS Alerts',
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'standard',
                'name' => 'Standard',
                'price' => 1999,
                'currency' => 'INR',
                'billing_cycle' => 'monthly',
                'max_students' => 800,
                'max_users' => null,
                'description' => 'The complete package for full automation.',
                'features' => [
                    'Up to 800 students',
                    'Everything in Basic',
                    'Exam & Report Cards',
                    'Homework & Notices',
                    'Parent Mobile App',
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'price' => 3499,
                'currency' => 'INR',
                'billing_cycle' => 'monthly',
                'max_students' => null, // unlimited
                'max_users' => null,
                'description' => 'Advanced analytics, unlimited students, dedicated support.',
                'features' => [
                    'Unlimited students',
                    'Everything in Standard',
                    'Online Classes',
                    'Custom Branding',
                    'Dedicated Manager',
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $p) {
            Plan::updateOrCreate(['slug' => $p['slug']], $p);
        }
    }
}
