@echo off
:: ============================================================
:: Auto CSV + Pinterest Import — Lance tous les profils Chrome
:: Planifier avec Windows Task Scheduler a l'heure souhaitee
:: ============================================================

set CHROME="C:\Program Files\Google\Chrome\Application\chrome.exe"

:: ── Etape 1 : Auto CSV (genere le CSV du jour) ───────────────
:: Ouvre posts-liste.php avec ?auto_csv=1 → auto-clique le bouton
%CHROME% --profile-directory="Profile 1" "http://localhost/SitePinterset/pinrecipes/posts-liste.php?auto_csv=1"

:: Attendre que l'auto CSV soit termine (ajuster selon la duree)
timeout /t 120 /nobreak >nul

:: ── Etape 2 : Import Pinterest (injecte le CSV) ───────────────
:: Ouvre Pinterest avec ?auto=1 → l'extension auto-injecte le CSV
%CHROME% --profile-directory="Profile 1" "https://www.pinterest.com/settings/bulk-create-pins/?auto=1"

:: Attendre 60s puis lancer le 2eme satellite
timeout /t 60 /nobreak >nul

:: ── Satellite 2 : LummyRecipes ───────────────────────────────
%CHROME% --profile-directory="Profile 2" "http://localhost/SitePinterset/LummyRecipes/posts-liste.php?auto_csv=1"
timeout /t 120 /nobreak >nul
%CHROME% --profile-directory="Profile 2" "https://www.pinterest.com/settings/bulk-create-pins/?auto=1"

:: Ajouter d'autres satellites ici :
:: timeout /t 60 /nobreak >nul
:: %CHROME% --profile-directory="Profile 3" "http://localhost/SitePinterset/Site3/posts-liste.php?auto_csv=1"
:: timeout /t 120 /nobreak >nul
:: %CHROME% --profile-directory="Profile 3" "https://www.pinterest.com/settings/bulk-create-pins/?auto=1"
