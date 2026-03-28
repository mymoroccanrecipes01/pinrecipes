@echo off
echo [1/2] Generation nouveaux posts...
"C:\xampp\php\php.exe" "C:\xampp\htdocs\SitePinterset\pinrecipes\auto-pipeline.php"

echo [2/2] Traitement CSV + reels (auto-daily-csv)...
"C:\xampp\php\php.exe" "C:\xampp\htdocs\SitePinterset\pinrecipes\auto-daily-csv.php"

echo Pipeline termine.
