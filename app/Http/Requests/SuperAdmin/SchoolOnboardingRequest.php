<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchoolOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') ?? false;
    }

    public function rules(): array
    {
        return [
            // 1. School Information
            'name'                     => ['required', 'string', 'max:255', 'unique:schools,name'],
            'code'                     => ['required', 'string', 'max:50'],
            'slug'                     => ['required', 'string', 'max:100', 'unique:schools,slug', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'email'                    => ['nullable', 'email', 'max:255'],
            'phone'                    => ['nullable', 'string', 'max:30'],
            'address'                  => ['nullable', 'string', 'max:500'],
            'city'                     => ['nullable', 'string', 'max:100'],
            'state'                    => ['nullable', 'string', 'max:100'],
            'country'                  => ['required', 'string', 'max:10'],
            'timezone'                 => ['required', 'string', 'max:50', 'timezone'],
            'currency'                 => ['required', 'string', 'max:10'],
            'language'                 => ['required', 'string', 'max:10'],
            'status'                   => ['nullable', 'string', 'in:active,inactive,suspended'],

            // 2. School Admin User
            'admin_name'               => ['required', 'string', 'max:255'],
            'admin_email'              => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_phone'              => ['nullable', 'string', 'max:30'],
            'admin_password'           => ['required', 'string', 'min:8'],

            // 3. Commercial Package, Billing Term & Activation
            'package_id'               => [
                'required',
                'integer',
                Rule::exists('packages', 'id')->where(function ($query) {
                    $query->where('is_active', true)->where('is_internal', false);
                }),
            ],
            'billing_term_months'      => ['required', 'integer', 'in:3,6,12'],
            'coupon_id'                => [
                'nullable',
                'integer',
                Rule::exists('coupons', 'id')->where('is_active', true),
            ],
            'start_date'               => ['required', 'date_format:Y-m-d'],
            'amount_paid'              => ['nullable', 'numeric', 'min:0'],
            'payment_method'           => ['nullable', 'string', 'max:50'],
            'notes'                    => ['nullable', 'string', 'max:500'],
            'activate_subscription'    => ['nullable', 'boolean'],
            'activate_unpaid_override' => ['nullable', 'boolean'],

            // 4. Academic Year
            'academic_year_name'       => ['nullable', 'string', 'max:100'],
            'academic_start'           => ['nullable', 'date_format:Y-m-d', 'required_with:academic_year_name'],
            'academic_end'             => ['nullable', 'date_format:Y-m-d', 'after:academic_start', 'required_with:academic_year_name'],
            'set_academic_current'     => ['nullable', 'boolean'],

            // 5. Custom Domain (Optional)
            'custom_domain'            => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $code = strtoupper(trim($this->input('code', '')));
            if ($code !== '') {
                // Canonical uniqueness check for school code stored in settings JSON
                $exists = School::where('settings->school_code', $code)->exists();
                if ($exists) {
                    $v->errors()->add('code', "A school with code '{$code}' already exists.");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.unique'                 => 'A school with this name already exists.',
            'code.required'               => 'School code is required.',
            'slug.unique'                 => 'A school with this URL slug already exists.',
            'slug.regex'                  => 'The slug must contain only lowercase letters, numbers, and hyphens.',
            'admin_email.unique'          => 'A user with this email address already exists.',
            'package_id.exists'           => 'Selected package is invalid, inactive, or not available for commercial assignment.',
            'billing_term_months.in'      => 'Commercial subscriptions are available only in 3, 6, or 12 month commitments.',
            'academic_end.after'          => 'Academic year end date must be after the start date.',
        ];
    }
}
