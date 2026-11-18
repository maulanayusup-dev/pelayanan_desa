<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureActiveUser;
use App\Models\Conversation;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    /**
     * Handle incoming chatbot conversation request from API or Web.
     * Enforces strict server-side authentication (NEVER trusts client user_id or role).
     */
    public function chat(Request $request, AiService $ai)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'conversation_id' => 'nullable|string',
        ]);

        $userMessage = trim($validated['message']);
        if (empty($userMessage)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pesan tidak boleh kosong.'
            ], 422);
        }

        // Strictly determine authenticated user from Sanctum token or Web session
        $authUser = null;
        if ($request->bearerToken() || $request->user('sanctum')) {
            $authUser = $request->user('sanctum');

            if (! $authUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Token autentikasi tidak valid, telah dicabut, atau akun sudah tidak aktif.',
                ], 401);
            }
        } elseif ($request->hasSession() && auth()->guard('web')->check()) {
            $authUser = auth()->guard('web')->user();
        }

        if ($authUser && ! $authUser->isActiveAccount()) {
            return EnsureActiveUser::denyInactiveResponse($request, $authUser);
        }

        $sessionId = $request->header('X-Session-ID');
        if (!$sessionId && $request->hasSession()) {
            $sessionId = $request->session()->getId();
        }
        if (!$sessionId) {
            try {
                $sessionId = session()->getId();
            } catch (\Throwable $e) {
                $sessionId = null;
            }
        }
        if (!$sessionId) {
            $sessionId = md5($request->ip() . ($request->userAgent() ?? 'guest'));
        }

        try {
            $conversation = null;
            $conversationId = $validated['conversation_id'] ?? null;

            if ($conversationId) {
                $query = Conversation::where('id', $conversationId);
                if ($authUser) {
                    $query->where('user_id', $authUser->id);
                } else {
                    $query->whereNull('user_id')->where('session_id', $sessionId);
                }
                $conversation = $query->first();
            }

            if (!$conversation) {
                $conversation = Conversation::create([
                    'user_id' => $authUser?->id,
                    'session_id' => $sessionId,
                    'title' => $authUser ? 'Percakapan ' . $authUser->name : 'Percakapan Tamu',
                    'current_service' => null,
                ]);
            }

            $reply = $ai->processConversation($conversation, $userMessage, $authUser);

            return response()->json([
                'status' => 'success',
                'conversation_id' => $conversation->id,
                'reply' => $reply,
                'authenticated' => $authUser !== null,
                'user' => $authUser ? [
                    'name' => $authUser->name,
                    'role' => $authUser->role?->name ?? 'masyarakat',
                ] : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('ChatbotController::chat error: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Maaf, terjadi kendala teknis saat memproses pesan Anda. Silakan coba kembali sesaat lagi.',
            ], 500);
        }
    }
}
