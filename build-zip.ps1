# Gera dist/rekapp-for-woocommerce.zip com a estrutura que o WordPress espera
# (pasta rekapp-for-woocommerce/ na raiz do zip).
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$stage = Join-Path $root "dist\stage\rekapp-for-woocommerce"
$zip = Join-Path $root "dist\rekapp-for-woocommerce.zip"

if (Test-Path (Join-Path $root "dist")) { Remove-Item (Join-Path $root "dist") -Recurse -Force }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

Copy-Item (Join-Path $root "rekapp-for-woocommerce.php") $stage
Copy-Item (Join-Path $root "uninstall.php") $stage
Copy-Item (Join-Path $root "README.md") $stage
Copy-Item (Join-Path $root "includes") $stage -Recurse
Copy-Item (Join-Path $root "assets") $stage -Recurse

Compress-Archive -Path $stage -DestinationPath $zip
Remove-Item (Join-Path $root "dist\stage") -Recurse -Force
Write-Output "OK: $zip"
