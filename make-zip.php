<?php
/**
 * make-zip.php — Gerador oficial do ZIP de release do plugin Front18.
 *
 * Uso: php make-zip.php
 *
 * Garante forward slashes nas entradas ZIP — compativel com Linux/WordPress.
 * Execute sempre da raiz do repositorio antes de criar um GitHub Release.
 */

$sourceDir = __DIR__;
$outputZip = __DIR__ . '/front18-wp-plugin.zip';
$slugInZip = 'front18-wp-plugin';

$exclude = [
    '.git',
    'make-zip.php',
    'front18-wp-plugin.zip',
];

if (file_exists($outputZip)) {
    unlink($outputZip);
}

$zip = new ZipArchive();
if ($zip->open($outputZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Erro: Nao foi possivel criar $outputZip\n");
}

$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
foreach ($items as $item) {
    // Caminho relativo com forward slashes
    $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceDir) + 1));

    // Verifica exclusoes
    $skip = false;
    foreach ($exclude as $ex) {
        if (strpos($relativePath, $ex) === 0 || strpos('/' . $relativePath . '/', '/' . $ex . '/') !== false) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    $entryName = $slugInZip . '/' . $relativePath;

    if ($item->isDir()) {
        $zip->addEmptyDir($entryName . '/');
    } else {
        $zip->addFile($item->getPathname(), $entryName);
        $count++;
    }
}

$zip->close();

$sizeKb = round(filesize($outputZip) / 1024);
echo "\n";
echo "  ✅ ZIP gerado: front18-wp-plugin.zip\n";
echo "  📁 Arquivos incluidos: $count\n";
echo "  📦 Tamanho: {$sizeKb} KB\n";
echo "\n";
echo "  Proximos passos:\n";
echo "  1. git add front18-wp-plugin.zip\n";
echo "  2. git commit -m 'release: vX.X.X'\n";
echo "  3. git push origin main\n";
echo "  4. git tag -a vX.X.X && git push origin vX.X.X\n";
echo "  5. GitHub Releases → upload deste ZIP como 'front18-wp-plugin.zip'\n";
echo "\n";
