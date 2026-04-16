<?php
$d = new RecursiveDirectoryIterator('.');
$i = new RecursiveIteratorIterator($d);
foreach ($i as $f) {
    if ($f->getExtension() === 'php') {
        $path = $f->getPathname();
        $content = file_get_contents($path);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            file_put_contents($path, substr($content, 3));
            echo "Fixed BOM in: $path\n";
        }
    }
}
echo "All BOMs removed!\n";
