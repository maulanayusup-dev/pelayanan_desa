<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportAttachments;
use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSystemReportMessageRequest extends FormRequest
{
    use ValidatesReportAttachments;

    public function authorize(): bool
    {
        $user = $this->user();
        /** @var SystemReport|null $report */
        $report = $this->route('report');

        if (! $user || ! $report) {
            return false;
        }

        if ($this->boolean('is_internal')) {
            return $user->can('createInternalNote', $report);
        }

        return $user->can('replyPublic', $report);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sender_id' => ['prohibited'],
            'report_id' => ['prohibited'],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:' . SystemReportAttachment::MAX_FILE_SIZE_KB],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendAttachmentContentValidation($validator);
    }
}

