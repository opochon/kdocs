@echo off
echo Starting Qdrant...

REM Create storage directory
if not exist "storage\qdrant" mkdir "storage\qdrant"

REM Check if container exists
docker ps -a --filter "name=kdocs-qdrant" --format "{{.Names}}" | findstr /i "kdocs-qdrant" >nul
if %errorlevel% equ 0 (
    echo Container exists, starting...
    docker start kdocs-qdrant
) else (
    echo Creating new container...
    docker run -d --name kdocs-qdrant --restart unless-stopped -p 6333:6333 -p 6334:6334 -v "%CD%\storage\qdrant:/qdrant/storage" qdrant/qdrant:latest
)

echo Waiting for Qdrant to start...
timeout /t 5 /nobreak >nul

curl -s http://localhost:6333/health
echo.
echo Done.
