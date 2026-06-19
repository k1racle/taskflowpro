<?php
$file = $argv[1] ?? '';
if (!$file || !file_exists($file)) {
    echo "Usage: php validate_html.php <file>\n";
    exit(1);
}
libxml_use_internal_errors(true);
$d = new DOMDocument();
$ok = $d->loadHTMLFile($file, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$errors = libxml_get_errors();
if (!$ok || $errors) {
    echo "FAIL $file\n";
    foreach ($errors as $e) {
        $msg = trim($e->message);
        if (str_contains($msg, 'Tag ') && str_contains($msg, 'invalid')) continue;
        echo "  line {$e->line}: $msg\n";
    }
} else {
    echo "OK $file\n";
}
