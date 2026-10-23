<?php

namespace Database\Seeders;

use App\Enums\EducationLevel;
use App\Models\Course;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $catalog = [
            EducationLevel::SeniorHigh->value => [
                'Accountancy, Business and Management (ABM)',
                'Humanities and Social Sciences (HUMSS)',
                'Science, Technology, Engineering and Mathematics (STEM)',
                'General Academic Strand (GAS)',
                'Arts and Design', 'Sports',
                'TVL - Information and Communications Technology',
                'TVL - Home Economics', 'TVL - Industrial Arts', 'TVL - Agri-Fishery Arts',
            ],
            EducationLevel::College->value => [
                'BS Accountancy', 'BS Accounting Information Systems', 'BS Agriculture',
                'BS Architecture', 'BS Biology', 'BS Business Administration', 'BS Chemistry',
                'BS Civil Engineering', 'BS Computer Engineering', 'BS Computer Science',
                'BS Criminology', 'BS Electrical Engineering', 'BS Electronics Engineering',
                'BS Entrepreneurship', 'BS Hospitality Management', 'BS Information Systems',
                'BS Information Technology', 'BS Mathematics', 'BS Mechanical Engineering',
                'BS Medical Technology', 'BS Nursing', 'BS Nutrition and Dietetics',
                'BS Pharmacy', 'BS Physical Therapy', 'BS Psychology', 'BS Social Work',
                'BS Tourism Management', 'Bachelor of Elementary Education',
                'Bachelor of Secondary Education', 'BA Communication', 'BA English Language',
                'BA Political Science', 'Bachelor of Fine Arts',
            ],
        ];
        foreach ($catalog as $level => $courses) {
            foreach ($courses as $name) {
                Course::query()->firstOrCreate(['name' => $name, 'education_level' => $level]);
            }
        }
    }
}
