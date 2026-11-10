<?php

use Illuminate\Support\Str;

it('creates foreign key targets before the November inventory migrations reference them', function () {
    $tables = [];
    $missing = [];
    $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
    sort($files);
    foreach ($files as $file) {
        $up = explode('public function down', file_get_contents($file))[0];
        preg_match_all("/Schema::create\(['\"]([^'\"]+)['\"]|foreignId\(['\"]([^'\"]+)['\"]\)[^;]*?->constrained\(([^)]*)\)/s", $up, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ($match[1] !== '') {
                $tables[$match[1]] = true;

                continue;
            }
            $target = trim($match[3], " \t\r\n'\"");
            $target = $target ?: Str::plural(Str::beforeLast($match[2], '_id'));
            if (str_starts_with(basename($file), '2026_11_10_') && ! isset($tables[$target])) {
                $missing[] = basename($file).': '.$target;
            }
        }
    }
    expect($missing)->toBe([]);
});
