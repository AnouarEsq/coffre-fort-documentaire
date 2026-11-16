# ============================================================
# Démarrage local du coffre-fort documentaire
#   .\demarrer.ps1
# PostgreSQL portable + serveur PHP + ouverture du navigateur.
# ============================================================
$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
$root = Split-Path -Parent $MyInvocation.MyCommand.Path

# --- Chemins (surchargeables par variables d'environnement) ---------------
$pgBin  = if ($env:PG_BIN)  { $env:PG_BIN }  else { 'C:\Users\hp\pgportable\bin' }
$pgData = if ($env:PG_DATA) { $env:PG_DATA } else { 'C:\Users\hp\pgdata' }
$php    = if ($env:PHP_BIN) { $env:PHP_BIN } else {
    'C:\Users\hp\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
}
if (-not (Test-Path $php)) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $php = $cmd.Source } else { throw "PHP introuvable. Définissez PHP_BIN." }
}
if (-not (Test-Path "$pgBin\pg_ctl.exe")) { throw "pg_ctl.exe introuvable dans $pgBin (définissez PG_BIN)." }
if (-not (Test-Path $pgData)) { throw "Dossier de données introuvable : $pgData (définissez PG_DATA)." }

# --- 1. PostgreSQL --------------------------------------------------------
& "$pgBin\pg_ctl.exe" -D $pgData status *> $null
if ($LASTEXITCODE -eq 0) {
    Write-Host '[OK] PostgreSQL est déjà démarré.' -ForegroundColor Green
} else {
    Write-Host '[..] Démarrage de PostgreSQL...' -ForegroundColor Yellow
    & "$pgBin\pg_ctl.exe" -D $pgData -l "$pgData\server.log" -o '-p 5432' start
    if ($LASTEXITCODE -ne 0) { throw "Échec du démarrage PostgreSQL. Voir $pgData\server.log" }
    Write-Host '[OK] PostgreSQL démarré sur le port 5432.' -ForegroundColor Green
}

# --- 2. Serveur PHP -------------------------------------------------------
$dejaActif = $false
try {
    $client = New-Object Net.Sockets.TcpClient
    $client.Connect('127.0.0.1', 8000)
    $client.Close()
    $dejaActif = $true
} catch { }

if ($dejaActif) {
    Write-Host '[OK] Le serveur web écoute déjà sur http://127.0.0.1:8000' -ForegroundColor Green
} else {
    Write-Host '[..] Démarrage du serveur PHP...' -ForegroundColor Yellow
    Start-Process -FilePath $php `
        -ArgumentList '-S', '127.0.0.1:8000', '-t', 'public' `
        -WorkingDirectory $root
    Start-Sleep -Seconds 2
    Write-Host '[OK] Serveur PHP démarré dans une nouvelle fenêtre.' -ForegroundColor Green
}

# --- 3. Navigateur --------------------------------------------------------
Start-Process 'http://127.0.0.1:8000'
Write-Host ''
Write-Host 'Coffre-fort accessible : http://127.0.0.1:8000' -ForegroundColor Cyan
Write-Host 'Comptes de démo      : admin@test.com / Admin#2026!' -ForegroundColor Cyan
Write-Host 'Ne fermez pas la fenêtre du serveur PHP pendant la démonstration.' -ForegroundColor DarkGray
