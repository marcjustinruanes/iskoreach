<?php

namespace App\Http\Requests\Provider;

use App\Models\Scholarship;
use App\Services\PhilippineLocations;
use App\Services\ScholarshipAcademicPriority;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SaveScholarshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('scholarship');

        return $item ? ($this->user()?->can('manage', $item) ?? false) : ($this->user()?->can('create', Scholarship::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('academic_year'))) {
            $this->merge(['academic_year' => preg_replace('/\s*-\s*/', '-', trim($this->input('academic_year')))]);
        }
        if (! $this->exists('currency')) {
            $this->merge(['currency' => 'PHP']);
        }
    }

    public function rules(): array
    {
        return ['purpose' => ['nullable', 'string', 'max:2000'], 'benefits_description' => ['nullable', 'string', 'max:4000'], 'slots' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'waitlist_slots' => ['nullable', 'integer', 'min:0', 'max:1000000'], 'application_method' => ['sometimes', Rule::in(['internal'])], 'external_application_url' => ['prohibited'],
            'version' => [$this->route('scholarship') ? 'required' : 'nullable', 'integer', 'min:0'],
            'title' => ['required', 'string', 'min:3', 'max:180'], 'description' => ['required', 'string', 'min:10', 'max:20000', InputRules::maxBytes(65535)],
            'scholarship_type' => ['sometimes', 'nullable', 'string', 'max:100'], 'benefit_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', Rule::in(['PHP'])], 'academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id'],
            'academic_year' => ['required_without:academic_term_id', ...InputRules::academicYear()],
            'opens_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'deadline_at' => ['nullable', 'date_format:Y-m-d\TH:i', ...($this->filled('opens_at') ? ['after:opens_at'] : [])],
            'existing_scholars_low_priority' => ['sometimes', 'boolean'], 'accepts_existing_scholars' => ['required', 'boolean'], 'eligibility_criteria' => ['nullable', 'string', 'max:6000'],
            'locations' => ['required', 'array', 'min:1', 'max:20'], 'locations.*' => ['array:scope,region_name,city_name,province_name'],
            'locations.*.scope' => ['required', Rule::in(['nationwide', 'region', 'province', 'city'])],
            'locations.*.province_name' => ['nullable', 'string', 'max:255'], 'locations.*.region_name' => ['nullable', 'string', 'max:255'], 'locations.*.city_name' => ['nullable', 'string', 'max:255'],
            'requirements' => ['present', 'array', 'max:30'], 'requirements.*' => ['array:name,description,required,requirement_stage,allowed_mime_types,max_size_kb'],
            'requirements.*.name' => ['required', 'string', 'max:255'], 'requirements.*.description' => ['nullable', 'string', 'max:2000'],
            'requirements.*.required' => ['required', 'boolean'], 'requirements.*.requirement_stage' => ['required', Rule::in(['application', 'renewal'])],
            'requirements.*.allowed_mime_types' => ['required', 'array', 'min:1', 'max:3'],
            'requirements.*.allowed_mime_types.*' => [Rule::in(['application/pdf', 'image/jpeg', 'image/png'])],
            'requirements.*.max_size_kb' => ['required', 'integer', 'between:1,10240']] + app(ScholarshipAcademicPriority::class)->rules($this->all());
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->any()) {
                return;
            }
            $seen = [];
            foreach ($this->input('locations', []) as $index => $location) {
                $scope = $location['scope'];
                if ($scope !== 'nationwide' && empty($location['region_name'])) {
                    $validator->errors()->add("locations.$index.region_name", 'Enter the region for this scope.');
                }
                if ($scope === 'city' && empty($location['city_name'])) {
                    $validator->errors()->add("locations.$index.city_name", 'Enter the city / municipality for this scope.');
                }
                if ($scope !== 'nationwide' && ! empty($location['region_name']) && ($scope !== 'city' || ! empty($location['city_name']))) {
                    try {
                        app(PhilippineLocations::class)->resolve($location);
                    } catch (ValidationException $error) {
                        $validator->errors()->add("locations.$index.region_name", $error->getMessage());
                    } catch (\RuntimeException $error) {
                        $validator->errors()->add("locations.$index.region_name", 'Location verification is unavailable. Retry when location options are available.');
                    }
                }
                $key = ($location['province_name'] ?? '').'|'.$scope.'|'.($scope === 'nationwide' ? '' : ($location['region_name'] ?? '')).'|'.($scope === 'city' ? ($location['city_name'] ?? '') : '');
                if (in_array($key, $seen, true)) {
                    $validator->errors()->add("locations.$index.scope", 'This location is already listed.');
                }
                $seen[] = $key;
            }
        }];
    }
}
