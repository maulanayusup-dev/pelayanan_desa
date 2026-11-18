<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportAttachments;
use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSystemReportRequest extends FormRequest
{
    use ValidatesReportAttachments;

    public function authorize(): bool
    {
        return $this->user()?->can('create', SystemReport::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reporter_id' => ['prohibited'],
            'reporter_role' => ['prohibited'],
            'assigned_to' => ['prohibited'],
            'status' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'category' => ['required', 'string', Rule::in(SystemReport::CATEGORIES)],
            'priority' => ['nullable', 'string', Rule::in(SystemReport::PRIORITIES)],
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'page_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    $trimmed = strtolower(trim($value));
                    if (preg_match('/^(javascript|data|vbscript):/i', $trimmed) === 1) {
                        $fail('URL halaman mengandung skema yang tidak diizinkan.');
                    }
                },
            ],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:' . SystemReportAttachment::MAX_FILE_SIZE_KB],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendAttachmentContentValidation($validator);
    }
}

