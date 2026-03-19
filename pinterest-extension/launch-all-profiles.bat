@echo off
:: ============================================================
:: Pinterest Auto CSV Import — Lance tous les profils Chrome
:: Planifier avec Windows Task Scheduler a l'heure souhaitee
:: ============================================================

set CHROME="C:\Program Files\Google\Chrome\Application\chrome.exe"
set URL="https://www.pinterest.com/settings/bulk-create-pins/"

:: ── Profil 1 : pinrecipes ────────────────────────────────────
%CHROME% --profile-directory="Profile 1" %URL%

:: Attendre 30s entre chaque profil (evite que Pinterest detecte ouvertures simultanees)
timeout /t 30 /nobreak >nul

:: ── Profil 2 : LummyRecipes ──────────────────────────────────
%CHROME% --profile-directory="Profile 2" %URL%

:: Ajouter d'autres profils ici si besoin :
:: timeout /t 30 /nobreak >nul
:: %CHROME% --profile-directory="Profile 3" %URL%
