<?php

namespace App\Services;

/**
 * Pemetaan intent statistik yang terstruktur dan murni (tanpa akses database):
 * subjek (pengajuan / akun warga yang pernah atau belum mengajukan), kategori status,
 * periode, perbandingan periode, serta konteks pertanyaan lanjutan.
 *
 * Hasil parser hanya menentukan APA yang dihitung. Angka selalu dihitung oleh
 * ApplicationStatisticsService (melalui VillageDataIntelligenceService) dan hak akses
 * ditegakkan di backend — LLM tidak pernah diminta menebak angka.
 */
class StatisticsQuestionParser
{
    /** Kosakata inti untuk toleransi salah ketik ringan (Levenshtein). */
    protected const TYPO_VOCABULARY = [
        'berapa', 'jumlah', 'pengajuan', 'permohonan', 'mengajukan', 'diajukan', 'pemohon', 'pengguna',
        'selesai', 'ditolak', 'disetujui', 'diteruskan', 'diproses', 'menunggu', 'pemeriksaan', 'diperiksa',
        'dikembalikan', 'dibandingkan', 'sebelumnya', 'kemarin', 'pernah', 'tersebut', 'supervisor', 'review',
        'laporan', 'keluhan', 'pengaduan', 'pelapor', 'prioritas', 'tampilkan', 'sebutkan',
    ];

    /** Singkatan / bahasa tidak baku yang umum dipakai warga. */
    protected const SLANG = [
        'brp' => 'berapa', 'brapa' => 'berapa', 'brpa' => 'berapa', 'berapakah' => 'berapa',
        'jmlh' => 'jumlah', 'jml' => 'jumlah', 'yg' => 'yang', 'blm' => 'belum', 'udh' => 'sudah',
        'udah' => 'sudah', 'sdh' => 'sudah', 'dah' => 'sudah', 'bln' => 'bulan', 'kmrn' => 'kemarin',
        'kemaren' => 'kemarin', 'gmn' => 'bagaimana', 'gimana' => 'bagaimana', 'gmna' => 'bagaimana',
        'klo' => 'kalau', 'kalo' => 'kalau', 'dr' => 'dari', 'dgn' => 'dengan', 'tdk' => 'tidak',
        'gak' => 'tidak', 'nggak' => 'tidak', 'ga' => 'tidak', 'engga' => 'tidak', 'tsb' => 'tersebut',
        'pengajuannya' => 'pengajuan', 'ajuan' => 'pengajuan', 'org' => 'orang', 'bnyk' => 'banyak',
        'laporannya' => 'laporan', 'keluhannya' => 'keluhan', 'pengaduannya' => 'pengaduan',
        'tiketnya' => 'tiket', 'pelapornya' => 'pelapor',
        'trus' => 'terus', 'lg' => 'lagi', 'mingdu' => 'minggu',
        'user-nya' => 'usernya', 'pengguna-nya' => 'penggunanya', 'pemohon-nya' => 'pemohonnya',
        'warga-nya' => 'warganya', 'orang-nya' => 'orangnya', 'akun-nya' => 'akunnya', 'nama-nya' => 'namanya',
    ];

    /** Kata "menunggu" tanpa tahap yang jelas → kartu "menunggu" pada dashboard role pengguna. */
    protected const WAITING_CATEGORY_BY_ROLE = [
        'staf' => 'menunggu_pemeriksaan_staf',
        'supervisor' => 'diproses',
        'kades' => 'diteruskan',
        'kepala_desa' => 'diteruskan',
    ];

    /**
     * Normalisasi ringan: huruf kecil, buang tanda baca, ganti singkatan, koreksi salah ketik 1–2 huruf
     * pada kata kunci statistik (mis. "pengajuann", "selsai", "mengajukn").
     */
    public function normalizeQuestion(string $message): string
    {
        $l = mb_strtolower($message);
        $l = preg_replace('/[^\p{L}\p{N}\s\/\-]/u', ' ', $l);
        $tokens = preg_split('/\s+/', trim($l)) ?: [];

        foreach ($tokens as &$t) {
            if (isset(self::SLANG[$t])) {
                $t = self::SLANG[$t];
                continue;
            }
            $len = mb_strlen($t);
            if ($len < 6 || preg_match('/\d/', $t) || in_array($t, self::TYPO_VOCABULARY, true)) {
                continue;
            }
            $best = null;
            $bestDistance = PHP_INT_MAX;
            foreach (self::TYPO_VOCABULARY as $word) {
                if (abs(strlen($word) - strlen($t)) > 2) {
                    continue;
                }
                $d = levenshtein($t, $word);
                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $best = $word;
                }
            }
            if ($best !== null && $bestDistance <= ($len >= 9 ? 2 : 1)) {
                $t = $best;
            }
        }
        unset($t);

        return implode(' ', $tokens);
    }

    /** Deteksi periode dari kalimat (sudah dinormalisasi). */
    public function detectPeriod(string $l): ?string
    {
        $map = [
            'last_week' => '/\b(minggu|pekan)\s+(lalu|kemarin|sebelumnya)\b/',
            'this_week' => '/\b(minggu|pekan)\s+ini\b/',
            'last_month' => '/\bbulan\s+(lalu|kemarin|sebelumnya)\b/',
            'this_month' => '/\bbulan\s+ini\b/',
            'last_year' => '/\btahun\s+(lalu|kemarin|sebelumnya)\b/',
            'this_year' => '/\btahun\s+ini\b/',
            'today' => '/\bhari\s+ini\b/',
            'yesterday' => '/\bkemarin\b/',
        ];
        foreach ($map as $period => $re) {
            if (preg_match($re, $l)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Petakan pertanyaan statistik ke kueri terstruktur. Mengembalikan null bila pertanyaan bukan
     * cakupan parser ini (ditangani intent lain yang sudah ada).
     *
     * @param array|null $context Konteks statistik jawaban sebelumnya (subject, category, period)
     * @return array{subject:string,category:?string,period:?string,compare_period:?string,document_type:?string}|null
     */
    public function parse(string $message, ?array $context = null, ?string $role = null): ?array
    {
        $l = $this->normalizeQuestion($message);
        if ($l === '') {
            return null;
        }
        $has = fn (string $re) => preg_match($re, $l) === 1;

        // Topik lain yang sudah punya intent sendiri (termasuk Sistem Laporan/Keluhan dan Role Account Intelligence)
        $hasSktmOrWorkflowWord = $has('/\b(pengajuan|permohonan|berkas|sktm|surat|pemohon|mengajukan|diajukan|ajukan)\b/')
            || $has('/\b(menunggu|persetujuan|approval|ttd|verifikasi|pemeriksaan|diperiksa|diteruskan|disetujui|ditolak|dikembalikan|revisi|review)\b/');

        if ($has('/\b(laporan|keluhan|pengaduan|tiket|pelapor)(nya)?\b/')
            || $has('/\brpt-[0-9a-z-]+/')
            || $has('/\b(model\s+ai|provider|penyedia|ai\s+eksternal|groq|gemini|google_ai_studio|openai|deepseek|openrouter)\b/')
            || $has('/\bmodel(?:\s+ai)?\s+yang\s+digunakan\b/')
            || (($context['subject'] ?? null) === 'reports' && !$has('/\b(pengajuan|permohonan|berkas|sktm|surat)\b/'))
            || (($context['subject'] ?? null) === 'role_accounts' && !$hasSktmOrWorkflowWord)
            || ($has('/\b(staf|staff|supervisor|spv|kades|kepala\s+desa|seluruh\s+akun|semua\s+akun)\b/') && !$hasSktmOrWorkflowWord)
            || ($has('/\b(warga|masyarakat)\b/') && $has('/\b(akun|user|users|pengguna)\b/') && !$hasSktmOrWorkflowWord)
            || $has('/\b(syarat|persyaratan|biaya|tarif|lama|estimasi|jam|fitur|subfitur|halaman|menu|role|peran|alur|tahapan|cara|prosedur|langkah)\b/')
            || $has('/\b(ringkasan|rekap|statistik)\b/')
            || $has('/selesai\s+atau\s+ditolak|ditolak\s+atau\s+selesai/')
            || $has('/\b(saya|aku|milikku|punyaku)\b/')
            || $has('/sktm-[0-9a-z]/')
            || (!$has('/\b(pengajuan|permohonan|berkas|surat)\b/') && $has('/\b(terdaftar|registrasi|aktif|nonaktif)\b/'))) {
            return null;
        }

        // Permintaan daftar nama ("siapa saja?", "sebutkan semua pemohon") — dijawab dari database
        $list = $this->parseListRequest($l, $context, $role);
        if ($list !== false) {
            return $list;
        }

        $countCue = $has('/\b(berapa|jumlah|total|banyak|banyaknya|hitung)\b/');
        $followCue = $has('/^(kalau|lalu|terus|bagaimana|dan|sedangkan|untuk|yang|dari|nah|jika|kalau\s+dibandingkan)\b/')
            || $has('/\b(tersebut|tadi)\b/');
        if (!$countCue && !$followCue) {
            return null;
        }

        // Kata "terus/lalu" di awal adalah penanda lanjutan, bukan status "diteruskan"
        $forCategory = preg_replace('/^(kalau|lalu|terus|nah|dan|sedangkan|jika)\s+/', '', $l);

        $periodMentioned = $this->detectPeriod($l);
        $compare = $has('/\b(dibanding\w*|banding\w*|perbandingan|versus|vs|naik|turun|selisih)\b/');
        $documentType = $has('/\bsktm\b|tidak\s+mampu/') ? 'SKTM' : null;

        // --- Subjek: akun warga yang pernah / belum pernah mengajukan
        $negated = $has('/\b(belum|tidak|tak)\s+(pernah\s+)?(mengajukan|melakukan\s+pengajuan|membuat\s+pengajuan|mengaju|ajukan|submit)\b/')
            || $has('/\byang\s+belum(\s+pernah)?\s*$/');
        $applyVerb = $has('/\b(mengajukan|diajukan|ajukan|mengaju|pengajuan|permohonan|submit)\b/');
        $accountCounted = $has('/\b(berapa|jumlah|total|banyak|banyaknya)\s+(jumlah\s+|banyak\s+|total\s+)?(akun|warga|pengguna|user|orang|pemohon|penduduk|masyarakat)\b/');

        $subject = null;
        if ($accountCounted && ($applyVerb || $negated || $has('/\bpemohon\b/'))) {
            $subject = $negated ? 'non_applicants' : 'applicants';
        }

        $applicationNoun = $has('/\b(pengajuan|permohonan|berkas|surat|sktm)\b/');
        $category = $this->detectCategory($forCategory);
        if ($category === null && $has('/\bmasuk\b/')) {
            $category = 'total';
        }
        $genericWaiting = $category === null && $has('/\b(menunggu|antre|antrean|antri|pending|tertunda)\b/');
        if ($genericWaiting) {
            $category = self::WAITING_CATEGORY_BY_ROLE[$role] ?? 'diproses';
        }

        $isStatsContext = is_array($context) && isset($context['subject']);
        $refersBack = $has('/\b(tersebut|tadi|itu)\b/') || $has('/^(dari|dan|lalu|terus|kalau|sedangkan|nah)\b/');

        if ($subject === null) {
            if ($applicationNoun && !$refersBack) {
                // Pertanyaan pengajuan baru (bukan lanjutan): hanya diklaim bila ada periode,
                // perbandingan, atau "menunggu" umum; kategori status biasa sudah ditangani intent lama.
                if (!$countCue || !($periodMentioned || $compare || $genericWaiting)) {
                    return null;
                }
                $subject = 'applications';
                $isStatsContext = false;
            } elseif ($isStatsContext) {
                // Pertanyaan lanjutan — pertahankan subjek/periode sebelumnya
                $subject = $context['subject'];
                if (in_array($subject, ['applicants', 'non_applicants'], true)) {
                    if ($has('/\bbelum\b/')) {
                        $subject = 'non_applicants';
                    } elseif ($has('/\b(sudah|pernah)\b/') && $category === null) {
                        $subject = 'applicants';
                    } elseif ($category !== null && $category !== 'total') {
                        $subject = 'applications';
                    }
                }
                $hasNewInfo = $periodMentioned || $compare || $category !== null || $subject !== $context['subject'];
                if (!$hasNewInfo) {
                    return null;
                }
                // Tanpa periode baru, lanjutan kategori biasa ditangani intent lama — kecuali ada periode
                // aktif yang harus dipertahankan, subjek akun, atau "menunggu" umum.
                if (!$periodMentioned && !$compare && !$genericWaiting && empty($context['period'])
                    && $subject === 'applications' && $context['subject'] === 'applications') {
                    return null;
                }
            } elseif (($countCue && ($periodMentioned || $genericWaiting)) || ($compare && $periodMentioned)) {
                $subject = 'applications';
            } else {
                return null;
            }
        }

        // --- Periode & perbandingan
        $previousPeriods = ['last_month' => 'this_month', 'yesterday' => 'today', 'last_week' => 'this_week', 'last_year' => 'this_year'];
        $inherit = $isStatsContext && ($refersBack || !$applicationNoun);
        $period = $periodMentioned;
        $comparePeriod = null;

        if ($compare) {
            if ($periodMentioned && isset($previousPeriods[$periodMentioned])) {
                $comparePeriod = $periodMentioned;
                $period = ($inherit ? ($context['period'] ?? null) : null) ?? $previousPeriods[$periodMentioned];
            } else {
                $period = $periodMentioned ?? ($inherit ? ($context['period'] ?? null) : null) ?? 'this_month';
                $comparePeriod = ApplicationStatisticsService::PREVIOUS_PERIOD[$period] ?? null;
            }
            if ($comparePeriod === null || $comparePeriod === $period) {
                [$period, $comparePeriod] = ['this_month', 'last_month'];
            }
        } elseif ($period === null && $inherit) {
            $period = $context['period'] ?? null;
        }

        if ($subject === 'applications' && $category === null) {
            $category = ($inherit && ($context['subject'] ?? null) === 'applications') ? ($context['category'] ?? 'total') : 'total';
        }

        return [
            'action' => 'count',
            'subject' => $subject,
            'category' => $subject === 'applications' ? $category : null,
            'period' => $subject === 'non_applicants' ? null : $period,
            'compare_period' => $subject === 'non_applicants' ? null : $comparePeriod,
            'document_type' => $documentType ?? ($inherit ? ($context['document_type'] ?? null) : null),
        ];
    }


    /** Kata yang tidak menambah makna pada permintaan daftar lanjutan ("siapa saja ya?"). */
    protected const LIST_FILLER = [
        'siapa', 'saja', 'aja', 'sajakah', 'yang', 'itu', 'tersebut', 'tadi', 'ya', 'dong', 'kah', 'kalau', 'lalu',
        'terus', 'nah', 'mana', 'nama', 'namanya', 'nama-nama', 'nama-namanya', 'orang', 'orangnya', 'pemohon',
        'pemohonnya', 'sebutkan', 'tampilkan', 'daftar', 'daftarnya', 'list', 'apa', 'dari', 'semua', 'semuanya',
        'tolong', 'coba', 'mereka', 'ada', 'boleh', 'minta', 'lihat', 'seluruh', 'seluruhnya', 'pengajuan', 'pengajuannya',
        'user', 'users', 'usernya', 'user-nya', 'pengguna', 'penggunanya', 'pengguna-nya', 'warga', 'warganya', 'warga-nya',
        'akun', 'akunnya', 'akun-nya', 'orang-nya', 'pemohon-nya', 'nama-nya', 'nya',
    ];

    /**
     * Kenali permintaan daftar pemohon. Mengembalikan:
     *  - array kueri dengan action 'list' (atau 'clarify' bila rujukan tidak jelas),
     *  - false bila kalimat bukan permintaan daftar (diproses parser hitungan / intent lain).
     */
    protected function parseListRequest(string $l, ?array $context, ?string $role): array|false
    {
        $has = fn (string $re) => preg_match($re, $l) === 1;

        $listCue = $has('/\b(siapa|sebutkan|tampilkan|list)\b/')
            || $has('/\bdaftar\s+(pemohon|pengajuan|permohonan|nama|warga|akun|berkas|user|users|pengguna)\b|\bdaftarnya\b/')
            || $has('/\bnama[- ]?nama|\bnama\s+(pemohon|warga|pengaju|orang|user|users|pengguna)|\b(namanya|pemohonnya|orangnya|usernya|user-nya|penggunanya|warganya)\b/');
        if (!$listCue) {
            return false;
        }

        // Pertanyaan "siapa" yang bukan permintaan data pemohon
        if ($has('/\b(laporan|keluhan|pengaduan|tiket|pelapor)(nya)?\b/')
            || $has('/\brpt-[0-9a-z-]+/')
            || $has('/\bsiapa\s+(kamu|anda|namamu)\b/')
            || $has('/\bsiapa\s+(saja\s+)?(yang\s+)?(bisa|boleh|berhak|dapat|harus|wajib|perlu)\b/')
            || $has('/\bsiapa\s+(saja\s+)?(yang\s+)?(menyetujui|memeriksa|meninjau|mereview|menandatangani|memverifikasi|menerbitkan|mengurus|bertugas|berwenang|mengelola|menangani)\b/')) {
            return false;
        }

        $isStatsContext = is_array($context) && isset($context['subject']);
        $period = $this->detectPeriod($l);
        $documentType = $has('/\bsktm\b|tidak\s+mampu/') ? 'SKTM' : ($isStatsContext ? ($context['document_type'] ?? null) : null);
        $all = $has('/\b(semua|semuanya|seluruh|seluruhnya|keseluruhan)\b/');

        // Kalimat lanjutan murni: hanya kata pengisi ("siapa saja?", "sebutkan namanya")
        $rest = array_diff(preg_split('/\s+/', $l) ?: [], self::LIST_FILLER);
        $pureFollowUp = $rest === [];

        // Subjek akun warga
        $subject = null;
        if ($has('/\b(belum|tidak|tak)\s+(pernah\s+)?(mengajukan|ajukan|mengaju|melakukan\s+pengajuan)\b/')) {
            $subject = 'non_applicants';
        } elseif ($has('/\b(akun|warga|pengguna|penduduk|masyarakat)\b/') && $has('/\b(mengajukan|ajukan|mengaju|pernah|pengajuan)\b/')) {
            $subject = 'applicants';
        }

        $forCategory = preg_replace('/^(kalau|lalu|terus|nah|dan|sedangkan|jika)\s+/', '', $l);
        $category = $subject ? null : $this->detectCategory($forCategory);
        if (!$subject && $category === null && $has('/\b(menunggu|antre|antrean|antri|pending|tertunda)\b/')) {
            $category = self::WAITING_CATEGORY_BY_ROLE[$role] ?? 'diproses';
        }
        if (!$subject && $category === null && $has('/\bmasuk\b/')) {
            $category = 'total';
        }
        $applicationNoun = $has('/\b(pengajuan|pengajuannya|permohonan|berkas|pemohon|pemohonnya|sktm|surat|mengajukan)\b/');

        if ($subject === null) {
            if ($category !== null || $period !== null || $applicationNoun || $all) {
                $subject = 'applications';
                if ($category === null) {
                    $sameSubject = $isStatsContext && ($context['subject'] ?? null) === 'applications';
                    $category = (!$all && $sameSubject && $pureFollowUp) ? ($context['category'] ?? 'total') : 'total';
                }
                if ($period === null && $isStatsContext && $pureFollowUp && !$all) {
                    $period = $context['period'] ?? null;
                }
                // "sebutkan pemohonnya" setelah pertanyaan akun → daftar akun
                if ($pureFollowUp && !$all && $isStatsContext && in_array($context['subject'], ['applicants', 'non_applicants'], true)) {
                    $subject = $context['subject'];
                    $category = null;
                }
            } elseif ($pureFollowUp && $isStatsContext) {
                $subject = $context['subject'];
                $category = $subject === 'applications' ? ($context['category'] ?? 'total') : null;
                $period = $context['period'] ?? null;
            } elseif ($pureFollowUp) {
                return ['action' => 'clarify', 'subject' => 'applications', 'category' => null, 'period' => null, 'compare_period' => null, 'document_type' => null];
            } else {
                return false;
            }
        }

        return [
            'action' => 'list',
            'subject' => $subject,
            'category' => $subject === 'applications' ? $category : null,
            'period' => $subject === 'non_applicants' ? null : $period,
            'compare_period' => null,
            'document_type' => $documentType,
        ];
    }

    /**
     * Petakan kalimat pengguna ke kategori statistik (definisi kartu dashboard). Null bila tidak dikenali.
     */
    public function detectCategory(string $message): ?string
    {
        $l = mb_strtolower($message);
        $has = fn (string $re) => preg_match($re, $l) === 1;

        if ($has('/\bmenunggu\s+(?:persetujuan\s+|approval\s+|ttd\s+|tanda\s*tangan\s+)?(?:kepala\s+desa|kades)\b/')
            || $has('/menunggu\s+(persetujuan|approval|ttd|tanda\s*tangan)/')) {
            return 'diteruskan';
        }

        $diteruskan = $has('/\b(di)?terus(kan)?\b|\bforward/');
        $disetujui = $has('/disetujui|setujui|\bacc\b|di-acc|\bapprove/');
        $selesai = $has('/selesai|diterbitkan|\bterbit/');

        if ($diteruskan && ($disetujui || $selesai)) {
            return 'diteruskan_disetujui';
        }
        if ($diteruskan) {
            return 'diteruskan';
        }
        if ($disetujui || $selesai) {
            return 'disetujui_selesai';
        }
        if ($has('/ditolak|\btolak|reject/')) {
            return 'ditolak';
        }
        if ($has('/dikembalikan|dikebalikan|kembalikan|revisi|diperbaiki|perbaikan/')) {
            return 'dikembalikan';
        }
        if ($has('/(review|telaah|tinjau)\w*\s+(supervisor|kasi)|menunggu\s+review/')) {
            return 'menunggu_review_supervisor';
        }
        if ($has('/(sedang|lagi|masih)\s+diperiksa|menunggu\s+pemeriksaan|perlu\s+diperiksa|\btahap\s+(staf|staff)\b|menunggu\s+(staf|staff)/')) {
            return 'menunggu_pemeriksaan_staf';
        }
        if ($has('/diperiksa/')) {
            return 'diperiksa';
        }
        if ($has('/pengajuan\s+baru|\bbaru\s+(masuk|diajukan)|yang\s+baru\b/')) {
            return 'baru';
        }
        if ($has('/diproses|dalam\s+proses|sedang\s+proses/')) {
            return 'diproses';
        }
        if ($has('/\b(dashboard|panel)\b/') || $has('/pengajuan\s+(yang\s+)?terdaftar/')) {
            return 'dashboard';
        }
        if ($has('/\b(total|semua|seluruh|keseluruhan)/')) {
            return 'total';
        }

        return null;
    }
}
