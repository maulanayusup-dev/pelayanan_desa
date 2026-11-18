@props(['attachments'])

@if($attachments && $attachments->count() > 0)
    <div class="flex flex-wrap gap-2 mt-2">
        @foreach($attachments as $attachment)
            @php
                $isPdf = $attachment->mime_type === 'application/pdf';
                $sizeKb = max(1, (int) ceil($attachment->file_size / 1024));
                $sizeText = $sizeKb >= 1024 ? number_format($sizeKb / 1024, 2) . ' MB' : $sizeKb . ' KB';
            @endphp
            <a href="{{ route('reports.attachments.download', $attachment) }}"
               target="_blank"
               rel="noopener"
               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-slate-700 dark:text-slate-200 hover:border-pink-400 hover:text-pink-600 dark:hover:text-pink-400 transition-colors shadow-soft-sm">
                <i class="fas {{ $isPdf ? 'fa-file-pdf text-rose-500' : 'fa-file-image text-blue-500' }}"></i>
                <span class="max-w-[180px] truncate">{{ $attachment->original_name }}</span>
                <span class="text-[10px] text-slate-400">({{ $sizeText }})</span>
                <i class="fas fa-download text-[10px] opacity-60"></i>
            </a>
        @endforeach
    </div>
@endif

