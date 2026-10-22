<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Machine translation of the minutes of meeting into Hindi, Marathi or Gujarati (Google Cloud Translation, v2).
 * Each piece of text is translated once and cached, so re-opening or re-sending the same minutes costs nothing.
 *
 * Views call $t('text') for everything a reader sees. Translator::render() draws the view twice: the first pass only
 * collects the texts, they are translated in one batch, and the second pass prints the translations.
 */
final class Translator
{
    public const LANGS = ['hi' => 'हिन्दी', 'mr' => 'मराठी', 'gu' => 'ગુજરાતી'];

    private const MAX_PIECE = 4500;      // characters per text sent to the API
    private const MAX_BATCH = 100;       // texts per request
    private const MAX_BATCH_CHARS = 25000;

    /** @var array<string,true> */
    private array $seen = [];
    /** @var array<string,string> */
    private array $map = [];
    private bool $collecting = true;

    private function __construct(private string $lang) {}

    public static function configured(): bool
    {
        return (string)config('translate.google_api_key', '') !== '';
    }

    public static function valid(mixed $lang): ?string
    {
        return is_string($lang) && isset(self::LANGS[$lang]) ? $lang : null;
    }

    /** Runs $draw($t) twice (collect, then print) and returns what the second run returns. Throws if the API call fails. */
    public static function render(string $lang, callable $draw): mixed
    {
        $tr = new self($lang);
        $t = fn($s) => $tr->t($s);
        $draw($t);
        $tr->resolve();
        return $draw($t);
    }

    public function t(mixed $s): string
    {
        $s = (string)($s ?? '');
        if (trim($s) === '' || !preg_match('/\p{L}/u', $s)) return $s;
        if ($this->collecting) { $this->seen[$s] = true; return $s; }
        return $this->map[$s] ?? $s;
    }

    private function resolve(): void
    {
        $this->collecting = false;
        if (!$this->seen) return;
        $parts = [];
        $pieces = [];
        foreach (array_keys($this->seen) as $s) {
            $parts[$s] = self::split($s);
            foreach ($parts[$s] as [$text, $translate]) if ($translate) $pieces[$text] = true;
        }
        $pieces = array_keys($pieces);
        $done = self::cacheGet($this->lang, $pieces);
        $todo = array_values(array_filter($pieces, fn($p) => !isset($done[$p])));
        if ($todo) {
            $out = self::callApi($todo, $this->lang);
            foreach ($todo as $i => $p) $done[$p] = $out[$i];
            self::cachePut($this->lang, $todo, $out);
        }
        foreach ($parts as $s => $list) {
            $this->map[(string)$s] = implode('', array_map(fn($x) => $x[1] ? ($done[$x[0]] ?? $x[0]) : $x[0], $list));
        }
    }

    /** Splits text into lines so paragraphs and line breaks survive. @return list<array{0:string,1:bool}> [text, needs translating] */
    private static function split(string $s): array
    {
        $out = [];
        foreach (preg_split('/(\R)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $line) {
            if ($i % 2 === 1 || trim($line) === '' || !preg_match('/\p{L}/u', $line)) { $out[] = [$line, false]; continue; }
            foreach (self::cut($line) as $c) $out[] = [$c, true];
        }
        return $out;
    }

    /** @return list<string> */
    private static function cut(string $line): array
    {
        $o = [];
        while (mb_strlen($line) > self::MAX_PIECE) {
            $cut = mb_strrpos(mb_substr($line, 0, self::MAX_PIECE), ' ') ?: self::MAX_PIECE;
            $o[] = mb_substr($line, 0, $cut);
            $line = mb_substr($line, $cut);
        }
        $o[] = $line;
        return $o;
    }

    /** @return array<string,string> source text => translation */
    private static function cacheGet(string $lang, array $texts): array
    {
        $found = [];
        try {
            $byHash = [];
            foreach ($texts as $t) $byHash[sha1($t)] = $t;
            foreach (array_chunk(array_keys($byHash), 200) as $chunk) {
                $rows = DB::all('SELECT src_hash, translated FROM translations WHERE lang = ? AND src_hash IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge([$lang], $chunk));
                foreach ($rows as $r) $found[$byHash[$r['src_hash']]] = (string)$r['translated'];
            }
        } catch (\Throwable $e) { /* cache table missing: translate without caching */ }
        return $found;
    }

    private static function cachePut(string $lang, array $texts, array $translated): void
    {
        try {
            foreach ($texts as $i => $t) {
                DB::run('INSERT IGNORE INTO translations (lang, src_hash, translated, created_at) VALUES (?,?,?, UTC_TIMESTAMP())', [$lang, sha1($t), (string)$translated[$i]]);
            }
        } catch (\Throwable $e) { /* best effort */ }
    }

    /** @return list<string> */
    private static function callApi(array $texts, string $lang): array
    {
        $key = (string)config('translate.google_api_key', '');
        $out = [];
        $batch = [];
        $chars = 0;
        $flush = function () use (&$batch, &$chars, &$out, $key, $lang): void {
            if (!$batch) return;
            $r = Http::request('POST', 'https://translation.googleapis.com/language/translate/v2', [
                'headers' => ['X-goog-api-key: ' . $key],
                'json' => ['q' => $batch, 'target' => $lang, 'format' => 'text'],
            ]);
            $items = is_array($r['body']) ? ($r['body']['data']['translations'] ?? null) : null;
            if ($r['status'] !== 200 || !is_array($items) || count($items) !== count($batch)) {
                $msg = is_array($r['body']) ? (string)($r['body']['error']['message'] ?? '') : '';
                throw new \RuntimeException('Google Translate said: ' . ($msg !== '' ? $msg : 'HTTP ' . $r['status']));
            }
            foreach ($items as $it) $out[] = (string)($it['translatedText'] ?? '');
            $batch = [];
            $chars = 0;
        };
        foreach ($texts as $t) {
            if ($batch && (count($batch) >= self::MAX_BATCH || $chars + mb_strlen($t) > self::MAX_BATCH_CHARS)) $flush();
            $batch[] = $t;
            $chars += mb_strlen($t);
        }
        $flush();
        return $out;
    }
}
