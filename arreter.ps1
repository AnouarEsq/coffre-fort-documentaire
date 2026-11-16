# ============================================================
# Arrêt local du coffre-fort documentaire
#   .\arreter.ps1
# Arrête le serveur PHP (port 8000) puis PostgreSQL.
# Les données restent dans le dossier pgdata.
# ============================================================
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
$pgBin  = if ($env:PG_BIN)  { $env:PG_BIN }  else { 'C:\Users\hp\pgportable\bin' }
$pgData = if ($env:PG_DATA) { $env:PG_DATA } else { 'C:\Users\hp\pgdata' }

# --- Serveur PHP sur le port 8000 ----------------------------------------
$connexions = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
if ($connexions) {
    $pids = $connexions | Select-Object -ExpandProperty OwningProcess -Unique
    foreach ($processId in $pids) {
        $proc = Get-Process -Id $processId -ErrorAction SilentlyContinue
        if ($proc -and $proc.ProcessName -eq 'php') {
            Stop-Process -Id $processId -Force
            Write-Host "[OK] Serveur PHP arrêté (PID $processId)." -ForegroundColor Green
        }
    }
} else {
    Write-Host '[OK] Aucun serveur PHP sur le port 8000.' -ForegroundColor Green
}

# --- PostgreSQL ----------------------------------------------------------
if (Test-Path "$pgBin\pg_ctl.exe") {
    & "$pgBin\pg_ctl.exe" -D $pgData stop
    if ($LASTEXITCODE -eq 0) {
        Write-Host '[OK] PostgreSQL arrêté.' -ForegroundColor Green
    } else {
        Write-Host '[OK] PostgreSQL ne tournait pas.' -ForegroundColor Green
    }
} else {
    Write-Host "[!] pg_ctl.exe introuvable dans $pgBin." -ForegroundColor Yellow
}
