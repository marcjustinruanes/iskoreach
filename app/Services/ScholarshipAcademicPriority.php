<?php

namespace App\Services;

use App\Enums\EducationLevel;
use App\Enums\YearLevel;
use App\Models\Scholarship;
use Illuminate\Validation\Rule;

class ScholarshipAcademicPriority
{
    /** @param array<string, mixed> $student */
    public function eligible(Scholarship $scholarship, array $student): bool
    {
        return empty($scholarship->eligibility_rules['provider_settings']['priority_education_level']) || $this->matches($scholarship, $student);
    }

    /** @param array<string, mixed> $input @return array<string, array<int, mixed>> */
    public function rules(array $input): array
    {
        $level = $input['priority_education_level'] ?? null;

        return [
            'priority_education_level' => ['nullable', Rule::in([...array_column(EducationLevel::cases(), 'value'), 'shs_graduate'])],
            'priority_courses' => ['sometimes', 'array', 'max:200'],
            'priority_courses.*' => ['required', 'string', 'distinct', 'max:255', Rule::exists('courses', 'name')->where('education_level', $level === 'shs_graduate' ? 'senior_high' : (is_string($level) ? $level : ''))],
            'priority_year_levels' => ['sometimes', 'array', 'max:8'],
            'priority_year_levels.*' => ['required', 'integer', 'distinct', Rule::in(array_map(fn (YearLevel $year): int => $year->value, array_filter(YearLevel::cases(), fn (YearLevel $year): bool => $year->educationLevel()->value === $level)))],
        ];
    }

    /** @param array<string, mixed> $student */
    public function matches(Scholarship $scholarship, array $student): bool
    {
        $settings = $scholarship->eligibility_rules['provider_settings'] ?? [];
        $level = $settings['priority_education_level'] ?? null;

        return filled($level) && ($level === 'shs_graduate' ? (($student['education_level'] ?? null) === 'senior_high' && filled($student['graduation_year'] ?? null)) : (($student['education_level'] ?? null) === $level))
            && (empty($settings['priority_courses']) || in_array($student['course'] ?? null, $settings['priority_courses'], true))
            && (empty($settings['priority_year_levels']) || in_array((int) ($student['year_level'] ?? 0), array_map('intval', $settings['priority_year_levels']), true));
    }

    public function summary(Scholarship $scholarship): ?string
    {
        $settings = $scholarship->eligibility_rules['provider_settings'] ?? [];
        $graduate = ($settings['priority_education_level'] ?? '') === 'shs_graduate';
        $level = EducationLevel::tryFrom($graduate ? 'senior_high' : ($settings['priority_education_level'] ?? ''));
        if (! $level) {
            return null;
        }
        $years = array_map(fn ($year): string => YearLevel::tryFrom((int) $year)?->label() ?? (string) $year, $settings['priority_year_levels'] ?? []);

        return implode(' / ', [$graduate ? 'SHS graduate' : $level->label(), empty($settings['priority_courses']) ? 'All courses / strands' : implode(', ', $settings['priority_courses']), $graduate ? 'Year graduated recorded' : ($years === [] ? 'All year levels' : implode(', ', $years))]);
    }
}
