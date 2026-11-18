<?php

use Livewire\Volt\Component;
use App\Services\AiService;
use App\Models\Conversation;
use App\Models\ChatMessage;

new class extends Component {
    public $isOpen = false;
    public $messages = [];
    public $userInput = '';
    public $conversationId = null;

    protected $listeners = ['open-chatbot' => 'openChat'];

    public function mount()
    {
        $this->loadOrCreateConversation();
    }

    public function openChat()
    {
        $this->isOpen = true;
    }

    public function toggleChat()
    {
        $this->isOpen = !$this->isOpen;
    }

    public function loadOrCreateConversation()
    {
        try {
            $userId = auth()->id();
            $sessionId = session()->getId();

            $conversation = null;
            if ($userId) {
                $conversation = Conversation::where('user_id', $userId)->latest()->first();
            } else {
                $conversation = Conversation::whereNull('user_id')->where('session_id', $sessionId)->latest()->first();
            }

            if (!$conversation) {
                $conversation = Conversation::create([
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'title' => $userId ? 'Percakapan ' . auth()->user()->name : 'Percakapan Layanan Desa',
                    'current_service' => null,
                ]);

                ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'Halo! Saya Asisten Virtual Pelayanan Desa. Ada yang bisa saya bantu mengenai pengajuan SKTM, laporan & keluhan masyarakat, atau layanan desa lainnya?',
                ]);
            }

            $this->conversationId = $conversation->id;
            $this->loadMessages();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Chatbot init error: ' . $e->getMessage());
        }
    }

    public function resetChat()
    {
        $userId = auth()->id();
        $sessionId = session()->getId();

        $conversation = Conversation::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'title' => 'Percakapan Baru',
            'current_service' => null,
        ]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Sesi percakapan baru dimulai. Ada informasi seputar pelayanan desa apa yang ingin Anda tanyakan?',
        ]);

        $this->conversationId = $conversation->id;
        $this->loadMessages();
    }

    public function loadMessages()
    {
        if ($this->conversationId) {
            $userId = auth()->id();
            $sessionId = session()->getId();

            $query = Conversation::where('id', $this->conversationId);
            if ($userId) {
                $query->where('user_id', $userId);
            } else {
                $query->whereNull('user_id')->where('session_id', $sessionId);
            }

            $conversation = $query->first();
            if ($conversation) {
                $this->messages = $conversation->messages->toArray();
            } else {
                $this->messages = [];
                $this->conversationId = null;
            }
        }
    }

    public function getStatusIndicator(): array
    {
        return app(\App\Services\AiConfigService::class)->getPublicStatus();
    }

    public function sendMessage(AiService $ai)
    {
        $input = trim($this->userInput);
        if (empty($input)) return;

        $this->userInput = '';

        try {
            $authUser = auth()->user();
            $sessionId = session()->getId();
            $conversation = null;

            if ($this->conversationId) {
                $query = Conversation::where('id', $this->conversationId);
                if ($authUser) {
                    $query->where('user_id', $authUser->id);
                } else {
                    $query->whereNull('user_id')->where('session_id', $sessionId);
                }
                $conversation = $query->first();
            }

            if (!$conversation) {
                $this->loadOrCreateConversation();
                if ($this->conversationId) {
                    $query = Conversation::where('id', $this->conversationId);
                    if ($authUser) {
                        $query->where('user_id', $authUser->id);
                    } else {
                        $query->whereNull('user_id')->where('session_id', $sessionId);
                    }
                    $conversation = $query->first();
                }
            }

            if ($conversation) {
                $ai->processConversation($conversation, $input, $authUser);
            }
            $this->loadMessages();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Chatbot Widget Exception: ' . $e->getMessage());
            $this->loadMessages();
        }
    }
};
?>
@php($aiStatus = $this->getStatusIndicator())
<div class="fixed bottom-6 right-6 z-50 font-sans">
    <!-- Chat Toggle Floating Button -->
    <button wire:click="toggleChat" class="relative w-14 h-14 bg-gradient-primary rounded-full flex items-center justify-center text-white shadow-soft-xl hover:scale-105 transition-all duration-300 focus:outline-none group cursor-pointer border-2 border-white/40">
        @if($isOpen)
            <i class="fas fa-times text-xl transition-transform duration-300 rotate-0 group-hover:rotate-90"></i>
        @else
            <i class="fas fa-robot text-2xl transition-transform duration-300 group-hover:-rotate-12"></i>
            <span class="absolute top-2 right-2 w-3 h-3 {{ $aiStatus['external_active'] ? 'bg-emerald-400' : 'bg-amber-400' }} border-2 border-white dark:border-slate-900 rounded-full"></span>
        @endif
    </button>

    <!-- Chat Window -->
    @if($isOpen)
    <div class="absolute bottom-20 right-0 w-80 sm:w-[380px] bg-white dark:bg-slate-800 rounded-2xl shadow-soft-xl overflow-hidden border border-slate-100 dark:border-slate-700 flex flex-col animate-fade-in-up" style="height: 520px; max-height: calc(100vh - 120px);">
        
        <!-- Header -->
        <div class="bg-gradient-primary p-4 text-white flex items-center justify-between shadow-soft">
            <div class="flex items-center">
                <div class="w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center mr-2.5 border border-white/20 shadow-sm shrink-0">
                    <i class="fas fa-robot text-base text-white"></i>
                </div>
                <div>
                    <h3 class="font-extrabold text-xs tracking-wide">Asisten Pelayanan Desa</h3>
                    <p class="text-[10px] text-white/85 font-medium flex items-center">
                        <span class="w-1.5 h-1.5 {{ $aiStatus['external_active'] ? 'bg-emerald-400' : 'bg-amber-400' }} rounded-full mr-1.5"></span>
                        {{ $aiStatus['label'] }}
                    </p>
                </div>
            </div>

            <button wire:click="resetChat" title="Mulai Percakapan Baru" class="px-2 py-1 text-white/85 hover:text-white bg-white/15 hover:bg-white/25 rounded-lg transition-all text-[11px] font-bold flex items-center gap-1 cursor-pointer">
                <i class="fas fa-rotate-right text-[10px]"></i>
                <span>Reset</span>
            </button>
        </div>

        <!-- Messages Area -->
        <div class="flex-1 p-4 overflow-y-auto bg-slate-50/50 dark:bg-slate-900/50 flex flex-col space-y-3" id="chatbox">
            <div class="text-center mb-1">
                <span class="text-[9px] font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500 bg-white dark:bg-slate-800 px-2.5 py-0.5 rounded-full border border-slate-100 dark:border-slate-700 shadow-sm">Percakapan Layanan</span>
            </div>
            
            @foreach($messages as $msg)
                @if($msg['role'] === 'user')
                    <div class="self-end max-w-[85%] animate-fade-in-up">
                        <div class="bg-gradient-primary text-white p-3 rounded-2xl rounded-tr-sm shadow-sm text-xs leading-relaxed font-medium">
                            {{ $msg['content'] }}
                        </div>
                    </div>
                @else
                    <div class="self-start max-w-[88%] flex items-start gap-2 animate-fade-in-up">
                        <div class="w-6 h-6 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-800 flex items-center justify-center shrink-0 mt-0.5 text-indigo-600 dark:text-indigo-400 text-[10px]">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 text-slate-700 dark:text-slate-200 p-3 rounded-2xl rounded-tl-sm shadow-soft text-xs leading-relaxed">
                            {!! nl2br(e($msg['content'])) !!}
                        </div>
                    </div>
                @endif
            @endforeach
            
            <div wire:loading wire:target="sendMessage" class="self-start max-w-[85%] flex items-center gap-2 animate-fade-in-up">
                <div class="w-6 h-6 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-800 flex items-center justify-center shrink-0 text-indigo-600 dark:text-indigo-400 text-[10px]">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 p-2.5 rounded-2xl rounded-tl-sm shadow-soft">
                    <div class="flex space-x-1 items-center h-2">
                        <div class="w-1.5 h-1.5 bg-indigo-400 rounded-full animate-bounce"></div>
                        <div class="w-1.5 h-1.5 bg-indigo-400 rounded-full animate-bounce" style="animation-delay: 0.15s"></div>
                        <div class="w-1.5 h-1.5 bg-indigo-400 rounded-full animate-bounce" style="animation-delay: 0.3s"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Input Area -->
        <div class="p-3 bg-white dark:bg-slate-800 border-t border-slate-100 dark:border-slate-700">
            <form wire:submit.prevent="sendMessage" class="relative flex items-center">
                <input wire:model="userInput" type="text" placeholder="Tanyakan syarat atau alur SKTM..." class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 text-xs py-2.5 pl-3.5 pr-10 rounded-xl focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 transition-all focus:bg-white dark:focus:bg-slate-900 placeholder:text-slate-400 dark:placeholder:text-slate-500" required>
                <button type="submit" wire:loading.attr="disabled" class="absolute right-1 w-7 h-7 bg-gradient-primary text-white rounded-lg flex items-center justify-center hover:opacity-95 transition-all shadow-sm disabled:opacity-50 cursor-pointer">
                    <i class="fas fa-paper-plane text-[10px]"></i>
                </button>
            </form>
            <div class="mt-1.5 text-center text-[10px] text-slate-400 dark:text-slate-500 font-medium">
                Sistem Pelayanan Desa &bull; Asisten Cerdas
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.hook('morph.updated', ({ el, component }) => {
                const chatbox = document.getElementById('chatbox');
                if(chatbox) {
                    chatbox.scrollTop = chatbox.scrollHeight;
                }
            });
        });
    </script>
    @endif
</div>
