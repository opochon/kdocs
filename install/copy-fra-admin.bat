@echo off
REM Copie fra.traineddata - A EXECUTER EN ADMINISTRATEUR
echo Copie de fra.traineddata vers Tesseract...
copy "%~dp0downloads\fra.traineddata" "C:\Program Files\Tesseract-OCR\tessdata\fra.traineddata"
if %errorlevel% equ 0 (
    echo [OK] fra.traineddata installe avec succes!
    "C:\Program Files\Tesseract-OCR\tesseract.exe" --list-langs
) else (
    echo [ERREUR] Echec de la copie. Executez ce script en administrateur.
)
pause
