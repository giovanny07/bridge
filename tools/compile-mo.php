<?php
/**
 * Compiles all .po files in locales/ into .mo binaries.
 * Usage: php tools/compile-mo.php
 */

$localesDir = dirname(__DIR__) . '/locales';

foreach (glob($localesDir . '/*.po') as $poFile) {
    $moFile = substr($poFile, 0, -3) . '.mo';
    compilePo($poFile, $moFile);
    echo 'Compiled: ' . basename($poFile) . ' -> ' . basename($moFile) . PHP_EOL;
}

function compilePo(string $poFile, string $moFile): void
{
    $entries = parsePo($poFile);

    $originals    = [];
    $translations = [];

    foreach ($entries as [$msgid, $msgstr]) {
        if ($msgid === '') {
            continue;
        }
        $originals[]    = $msgid;
        $translations[] = $msgstr !== '' ? $msgstr : $msgid;
    }

    $n = count($originals);

    // Sort by original string (MO format requires sorted keys)
    array_multisort($originals, SORT_STRING, $translations);

    $oStrings = implode("\0", $originals) . "\0";
    $tStrings = implode("\0", $translations) . "\0";

    // Header offsets: magic, revision, n_strings, orig_offset, trans_offset, hash_size, hash_offset
    $headerSize  = 7 * 4;
    $origTable   = $headerSize;
    $transTable  = $origTable + $n * 8;
    $stringsBase = $transTable + $n * 8;

    $oOffsets = [];
    $tOffsets = [];
    $pos = 0;
    foreach ($originals as $s) {
        $len = strlen($s);
        $oOffsets[] = [$len, $stringsBase + $pos];
        $pos += $len + 1;
    }
    $tBase = $stringsBase + strlen($oStrings);
    $pos = 0;
    foreach ($translations as $s) {
        $len = strlen($s);
        $tOffsets[] = [$len, $tBase + $pos];
        $pos += $len + 1;
    }

    $data  = pack('VVVVVVV', 0x950412de, 0, $n, $origTable, $transTable, 0, $stringsBase + strlen($oStrings) + strlen($tStrings));
    foreach ($oOffsets as [$len, $off]) {
        $data .= pack('VV', $len, $off);
    }
    foreach ($tOffsets as [$len, $off]) {
        $data .= pack('VV', $len, $off);
    }
    $data .= $oStrings . $tStrings;

    file_put_contents($moFile, $data);
}

function parsePo(string $file): array
{
    $lines   = file($file, FILE_IGNORE_NEW_LINES);
    $entries = [];
    $msgid   = null;
    $msgstr  = null;

    $flush = function () use (&$entries, &$msgid, &$msgstr): void {
        if ($msgid !== null) {
            $entries[] = [$msgid, $msgstr ?? ''];
        }
        $msgid  = null;
        $msgstr = null;
    };

    foreach ($lines as $line) {
        $line = rtrim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'msgid ')) {
            $flush();
            $msgid = parseString(substr($line, 6));
        } elseif (str_starts_with($line, 'msgstr ')) {
            $msgstr = parseString(substr($line, 7));
        } elseif (str_starts_with($line, '"')) {
            $val = parseString($line);
            if ($msgstr !== null) {
                $msgstr .= $val;
            } elseif ($msgid !== null) {
                $msgid .= $val;
            }
        }
    }

    $flush();

    return $entries;
}

function parseString(string $s): string
{
    $s = trim($s);
    if (str_starts_with($s, '"') && str_ends_with($s, '"')) {
        $s = substr($s, 1, -1);
    }
    return stripcslashes($s);
}
