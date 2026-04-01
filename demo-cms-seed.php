<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $school = \App\Models\School::first();
    if ($school) {
        // 1. Update School Configs with ALL new advanced properties
        $school->update([
            'admission_form_config' => [
                'enable_admission' => true,
                'require_photo' => true,
                'require_parent_name' => true,
                'require_phone' => true,
                'require_email' => true,
                'require_address' => true,
                'require_occupation' => true,
                'require_previous_school' => false,
            ],
            'landing_theme_config' => [
                'primary_color' => '#1d4ed8',
                'secondary_color' => '#f8fafc',
                'button_text_color' => '#ffffff',
                'footer_bg_color' => '#0f172a',
                'font_family' => 'sans-serif',
                'show_about' => true,
                'show_admissions' => true,
                'show_contact' => true,
                'seo_title' => $school->name . ' - A Premier Institute',
                'seo_description' => 'Discover the best education in town at ' . $school->name . '. Apply online today.',
                'social_facebook' => 'https://facebook.com/demo',
                'social_twitter' => 'https://twitter.com/demo',
                'social_instagram' => 'https://instagram.com/demo',
                'google_map_embed' => '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d113063.63345862024!2d-84.47547144186595!3d33.8471415053229!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x88f5045d6993098d%3A0x66fede2f990b630b!2sAtlanta%2C%20GA!5e0!3m2!1sen!2sus!4v1714571212345!5m2!1sen!2sus" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>'
            ],
            'about_text' => 'We are dedicated to providing an environment that fosters personal and intellectual development. Our curriculum is designed to challenge students, inspiring them to explore their passions and achieve their highest potential within a supportive community setting.',
            'tagline' => 'Empowering Minds, Shaping the Future'
        ]);

        // 2. Clear old data
        \App\Models\LandingBanner::where('school_id', $school->id)->delete();
        \App\Models\LandingSection::where('school_id', $school->id)->delete();
        \App\Models\AdmissionApplication::where('school_id', $school->id)->delete();
        \App\Models\TimetableEntry::where('school_id', $school->id)->delete();

        // 3. Insert Banners
        \App\Models\LandingBanner::create([
            'school_id' => $school->id,
            'title' => 'Welcome to ' . $school->name,
            'subtitle' => 'Empowering minds, shaping the future.',
            'image_path' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=1000',
            'sort_order' => 1
        ]);
        \App\Models\LandingBanner::create([
            'school_id' => $school->id,
            'title' => 'State of the Art Facilities',
            'subtitle' => 'Giving students the tools they need to succeed.',
            'image_path' => 'https://images.unsplash.com/photo-1562774053-701939374585?q=80&w=1000',
            'sort_order' => 2
        ]);

        // 4. Insert Top Performers Section (Grid)
        $section1 = \App\Models\LandingSection::create([
            'school_id' => $school->id,
            'title' => 'Our Top Performers 2025',
            'type' => 'grid',
            'sort_order' => 1
        ]);

        \App\Models\LandingSectionCard::create([
            'landing_section_id' => $section1->id,
            'title' => 'Alice Johnson',
            'description' => '99.8% in Final Grade 12 Boards. Currently at MIT.',
            'image_path' => 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?q=80&w=300'
        ]);
        \App\Models\LandingSectionCard::create([
            'landing_section_id' => $section1->id,
            'title' => 'Brian Smith',
            'description' => '98.5% in Final Grade 12 Boards. Currently at Stanford.',
            'image_path' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=300'
        ]);
        \App\Models\LandingSectionCard::create([
            'landing_section_id' => $section1->id,
            'title' => 'Chloe Davis',
            'description' => '97.2% in Final Grade 12 Boards. Currently at Oxford.',
            'image_path' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?q=80&w=300'
        ]);

        // 5. Insert Marquee Alumni Section
        $section2 = \App\Models\LandingSection::create([
            'school_id' => $school->id,
            'title' => 'Our Distinguished Alumni',
            'type' => 'marquee',
            'sort_order' => 2
        ]);

        $alumniData = [
            ['title' => 'Dr. A. Sharma', 'description' => 'Renowned Cardiologist, running the largest care network in state.', 'image_path' => 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?q=80&w=200'],
            ['title' => 'E. Roberts', 'description' => 'Founder of Tech Innovate, revolutionizing the AI industry locally.', 'image_path' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=200'],
            ['title' => 'S. Jackson', 'description' => 'Olympic Gold Medalist in Track and Field events for 2024.', 'image_path' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?q=80&w=200'],
            ['title' => 'J. Doe', 'description' => 'Award winning author with over 5 best-sellers worldwide.', 'image_path' => 'https://images.unsplash.com/photo-1531427186611-ecfd6d936c79?q=80&w=200'],
            ['title' => 'M. Lee', 'description' => 'Director of Environmental Sciences at the Global Nature Fund.', 'image_path' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?q=80&w=200'],
            ['title' => 'P. Kim', 'description' => 'Former Ambassador to the UN, currently advising government.', 'image_path' => 'https://images.unsplash.com/photo-1560250097-0b93528c311a?q=80&w=200'],
        ];

        foreach ($alumniData as $card) {
            \App\Models\LandingSectionCard::create(array_merge($card, ['landing_section_id' => $section2->id]));
        }

        // 6. Timetable Data
        $schoolClasses = \App\Models\SchoolClass::where('school_id', $school->id)->take(3)->get();
        $subjects = \App\Models\Subject::take(5)->get();
        $teachers = \App\Models\User::take(5)->get(); // Fallback to any user if no teachers
        $classrooms = \App\Models\Classroom::take(3)->get();

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        foreach ($schoolClasses as $class) {
            foreach ($days as $day) {
                \App\Models\TimetableEntry::create([
                    'school_id' => $school->id,
                    'school_class_id' => $class->id,
                    'subject_id' => $subjects->random()->id ?? null,
                    'user_id' => $teachers->random()->id ?? null,
                    'classroom_id' => $classrooms->random()->id ?? null,
                    'day_of_week' => $day,
                    'start_time' => '09:00:00',
                    'end_time' => '10:00:00',
                    'is_active' => true,
                ]);
            }
        }

        echo "CMS Demo data & Timetable seeded for school: " . $school->name . "\n";
    } else {
        echo "No school found to seed.\n";
    }
} catch (\Exception $e) {
    echo "General Exception: " . $e->getMessage() . "\n";
}
