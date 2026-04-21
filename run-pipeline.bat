@echo off
echo [1/2] Generation nouveaux posts...
"C:\xampp\php\php.exe" "%~dp0auto-pipeline.php"

echo [2/2] Traitement CSV + templates + satellites (auto-daily-csv)...
"C:\xampp\php\php.exe" "%~dp0auto-daily-csv.php"

echo Pipeline termine.
