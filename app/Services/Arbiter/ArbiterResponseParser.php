<?php

namespace App\Services\Arbiter;

/**
 * Parses machine verdict from LLM reply: last line {@see self::LINE_PREFIX}{"verdict":"..."}.
 */
final class ArbiterResponseParser
{
    public const LINE_PREFIX = 'XBOARD_ARBITER_JSON:';

    /** @var list<string> */
    public const ALLOWED_VERDICTS = [
        'manual_review',
        'favor_buyer',
        'favor_seller',
        'continue_review',
    ];

    /**
     * @return array{display: string, verdict: string}
     */
    public static function splitDisplayAndVerdict(string $rawText): array
    {
        $raw = preg_replace("/\r\n?/", "\n", $rawText) ?? $rawText;
        $lines = explode("\n", rtrim($raw, "\n"));
        $verdictLineIndex = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, self::LINE_PREFIX)) {
                $verdictLineIndex = $i;
                break;
            }
        }

        if ($verdictLineIndex === null) {
            return ['display' => trim($raw), 'verdict' => 'continue_review'];
        }

        $jsonPart = trim(substr(trim($lines[$verdictLineIndex]), strlen(self::LINE_PREFIX)));
        $displayLines = array_slice($lines, 0, $verdictLineIndex);
        $display = trim(implode("\n", $displayLines));

        $parsed = json_decode($jsonPart, true);
        $v = is_array($parsed) ? trim((string) ($parsed['verdict'] ?? '')) : '';
        $verdict = in_array($v, self::ALLOWED_VERDICTS, true) ? $v : 'continue_review';

        if ($display === '') {
            $display = trim(implode("\n", array_slice($lines, 0, $verdictLineIndex)));
        }
        if ($display === '') {
            $display = trim(str_replace($lines[$verdictLineIndex], '', $raw));
        }

        return ['display' => $display, 'verdict' => $verdict];
    }
}
