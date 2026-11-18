<?php

namespace App\Http\Requests;

use App\Models\SystemReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemReportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SystemReport|null $report */
        $report = $this->route('report');
        if (! $report) {
            return false;
        }

        $ability = $this->input('status') === SystemReport::STATUS_RESOLVED
            ? 'resolve'
            : 'updateStatus';

        return $this->user()?->can($ability, $report) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(SystemReport::STATUSES)],
            'resolution_summary' => ['nullable', 'string', 'max:3000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

