# Gera dist/rekapp-for-woocommerce.zip no formato que o WordPress espera.
#
# NÃO use Compress-Archive aqui: no Windows PowerShell ele grava os caminhos
# internos com barra invertida ("pasta\arquivo"), e o padrão ZIP exige "/".
# O descompactador do PHP lê "pasta\arquivo" como um nome de arquivo único, o
# plugin é extraído sem estrutura de pastas e o WordPress responde
# "O arquivo do plugin não existe" na ativação.
#
# Aqui cada entrada é escrita à mão com o separador correto.
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$distDir = Join-Path $root "dist"
$zipPath = Join-Path $distDir "rekapp-for-woocommerce.zip"
$pluginSlug = "rekapp-for-woocommerce"

if (Test-Path $distDir) { Remove-Item $distDir -Recurse -Force }
New-Item -ItemType Directory -Force -Path $distDir | Out-Null

# Arquivos que entram no pacote (o resto é ferramenta de desenvolvimento).
$files = @()
$files += Get-Item (Join-Path $root "rekapp-for-woocommerce.php")
$files += Get-Item (Join-Path $root "uninstall.php")
$files += Get-Item (Join-Path $root "README.md")
$files += Get-ChildItem (Join-Path $root "includes") -Recurse -File
$files += Get-ChildItem (Join-Path $root "assets") -Recurse -File

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
try {
    $zip = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in $files) {
            $relative = $file.FullName.Substring($root.Length).TrimStart('\', '/')
            # O separador do zip é sempre "/", independente do sistema.
            $entryName = "$pluginSlug/" + ($relative -replace '\\', '/')
            $entry = $zip.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
            $entryStream = $entry.Open()
            try {
                $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
                $entryStream.Write($bytes, 0, $bytes.Length)
            } finally {
                $entryStream.Dispose()
            }
        }
    } finally {
        $zip.Dispose()
    }
} finally {
    $stream.Dispose()
}

# Verificação: um zip com "\" no nome das entradas não instala no WordPress,
# e o erro só aparece na loja do lojista. Falhar aqui é mais barato.
$check = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $invalid = $check.Entries | Where-Object { $_.FullName -match '\\' }
    if ($invalid) {
        throw "Zip inválido: entradas com barra invertida ($($invalid[0].FullName))"
    }
    $mainFile = $check.Entries | Where-Object { $_.FullName -eq "$pluginSlug/rekapp-for-woocommerce.php" }
    if (-not $mainFile) {
        throw "Zip inválido: $pluginSlug/rekapp-for-woocommerce.php não encontrado na raiz do pacote"
    }
    Write-Output "OK: $zipPath ($($check.Entries.Count) arquivos)"
} finally {
    $check.Dispose()
}
