<?php

namespace App\Http\Requests;

use App\Models\SystemReport;
use Illuminate\Foundation\Http\FormRequest;

class AssignSystemReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SystemReport|null $report */
        $report = $this->route('report');

        return $report ? ($this->user()?->can('assign', $report) ?? false) : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
        ];
    }
}

