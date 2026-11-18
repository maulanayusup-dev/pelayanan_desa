<?php

namespace App\Http\Requests;

use App\Models\SystemReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemReportPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SystemReport|null $report */
        $report = $this->route('report');

        return $report ? ($this->user()?->can('updatePriority', $report) ?? false) : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'priority' => ['required', 'string', Rule::in(SystemReport::PRIORITIES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

