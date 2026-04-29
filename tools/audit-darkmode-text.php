<?php
/**
 * audit-darkmode-text.php — scan Vue templates for likely dark-mode text bugs.
 *
 * Categories flagged :
 *   1. Element has `text-{slate|gray|zinc|neutral|stone}-[6-9]00` without a
 *      paired `dark:text-` class (will be near-black in dark mode).
 *   2. Element has `bg-{color}-50/100` without a `dark:bg-` pair (light bg in
 *      both modes — looks like a stuck card).
 *   3. Direct text-bearing elements (<p>, <h1-6>, <li>, <td>, <span>, <a>,
 *      <button>, <label>) that have NO text-* class at all and aren't an
 *      icon-only button.
 *
 * Usage :
 *   php tools/audit-darkmode-text.php [path] [--format=text|json]
 *
 * Output : list of file:line + matched class string + category code, sorted
 * by file. Designed as a triage list, not a hard failure : some hits are
 * intentional (purely decorative spans inside colored pills, etc.).
 */

$root = $argv[1] ?? 'resources/js/Pages';
$format = 'text';
foreach ($argv as $a) if (str_starts_with($a, '--format=')) $format = substr($a, 9);

if (! is_dir($root)) {
    fwrite(STDERR, "Path not found: $root\n");
    exit(1);
}

$files = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.vue')) {
        $files[] = $f->getPathname();
    }
}

$findings = [];

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        $lineNum = $i + 1;

        // Skip script + style blocks (rough — assumes <template> is the rest)
        if (preg_match('/^\s*(import|const|function|<script|<\/script>|<style|<\/style>|\s*\/\/|\s*\*|\}\s*$)/', $line)) continue;

        // Category 1 : light-mode text color but no dark counterpart on same line.
        if (preg_match('/text-(?:slate|gray|zinc|neutral|stone)-[6-9]00/', $line)
            && ! preg_match('/dark:text-/', $line)) {
            $findings[] = ['file' => $file, 'line' => $lineNum, 'cat' => 1,
                'reason' => 'text-{shade}-[6-9]00 with no dark: pair', 'snippet' => trim($line)];
        }

        // Category 2 : light bg without dark counterpart.
        if (preg_match('/bg-(?:slate|gray|zinc|white|red|amber|emerald|indigo|rose|orange|blue)-(?:50|100)\b/', $line)
            && ! preg_match('/dark:bg-/', $line)
            && ! preg_match('/(class|className)\s*=\s*"[^"]*\b(prose|input|select|textarea)\b/', $line)) {
            $findings[] = ['file' => $file, 'line' => $lineNum, 'cat' => 2,
                'reason' => 'light bg with no dark: pair', 'snippet' => trim($line)];
        }

        // Category 3 : text-bearing tag with no text-* class anywhere on the line.
        // Skip when the element is icon-only (has aria-hidden or is a self-
        // closing icon component) or when class is missing entirely.
        if (preg_match('/<(p|h[1-6]|li|td|th|label)\b[^>]*class="([^"]*)"/', $line, $m)) {
            $tag = $m[1];
            $classes = $m[2];
            if (! preg_match('/\btext-/', $classes)
                && ! preg_match('/\bsr-only\b/', $classes)) {
                $findings[] = ['file' => $file, 'line' => $lineNum, 'cat' => 3,
                    'reason' => "<$tag> with class but no text-* color", 'snippet' => trim($line)];
            }
        }
    }
}

if ($format === 'json') {
    echo json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit(0);
}

// Text report grouped by category
$byCat = ['1' => [], '2' => [], '3' => []];
foreach ($findings as $f) $byCat[$f['cat']][] = $f;

$labels = [
    '1' => 'Text-color without dark: pair (would be near-black in dark mode)',
    '2' => 'Light background without dark: pair (stuck card in dark mode)',
    '3' => 'Text-bearing tag with class but no text-* color (inherits unpredictably)',
];

foreach (['1', '2', '3'] as $cat) {
    if (empty($byCat[$cat])) continue;
    echo "\n=== Category $cat — " . $labels[$cat] . " (" . count($byCat[$cat]) . ") ===\n";
    foreach ($byCat[$cat] as $f) {
        echo $f['file'] . ':' . $f['line'] . "\n  " . substr($f['snippet'], 0, 200) . "\n";
    }
}

echo "\nTotal findings: " . count($findings) . "\n";
