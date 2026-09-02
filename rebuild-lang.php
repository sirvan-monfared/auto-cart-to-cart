<?php

// Rebuild the translation layer the robust way:
//  - lang/{fa,en}.json  → JSON string-key translations (loaded for plain __('...')
//    calls via loadJsonTranslationsFrom; keys with dots are preserved literally)
//  - lang/{fa,en}/cardpay.php → ONLY the settings.section/settings.label groups,
//    for the dynamic __('cardpay::settings.section.'.$section) lookups.
require __DIR__.'/vendor/autoload.php';

function flattenPrefix($arr, $prefix = '')
{
    $out = [];
    foreach ($arr as $k => $v) {
        $key = $prefix === '' ? $k : $prefix.'.'.$k;
        if (is_array($v)) {
            $out += flattenPrefix($v, $key);
        } else {
            $out[$key] = $v;
        }
    }

    return $out;
}

$fa = require __DIR__.'/resources/lang/fa/cardpay.php';
$en = require __DIR__.'/resources/lang/en/cardpay.php';

$faFlat = flattenPrefix($fa);
$enFlat = flattenPrefix($en);

// Split: settings.* goes to the group file; everything else is a JSON key.
$faJson = [];
$faGroup = [];
foreach ($faFlat as $k => $v) {
    if (str_starts_with($k, 'settings.')) {
        $keys = explode('.', $k); // settings.section.general
        $faGroup[$keys[1]][$keys[2]] = $v;
    } else {
        $faJson[$k] = $v;
    }
}
$enJson = [];
$enGroup = [];
foreach ($enFlat as $k => $v) {
    if (str_starts_with($k, 'settings.')) {
        $keys = explode('.', $k);
        $enGroup[$keys[1]][$keys[2]] = $v;
    } else {
        $enJson[$k] = $v;
    }
}

file_put_contents(__DIR__.'/resources/lang/fa.json', json_encode($faJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents(__DIR__.'/resources/lang/en.json', json_encode($enJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

file_put_contents(__DIR__.'/resources/lang/fa/cardpay.php', "<?php\n\nreturn ".var_export($faGroup, true).";\n");
file_put_contents(__DIR__.'/resources/lang/en/cardpay.php', "<?php\n\nreturn ".var_export($enGroup, true).";\n");

echo 'fa.json keys: '.count($faJson).', en.json keys: '.count($enJson)."\n";
echo 'fa group: '.count($faGroup['section'] ?? []).+' sections, '.count($faGroup['label'] ?? []).+" labels\n";
