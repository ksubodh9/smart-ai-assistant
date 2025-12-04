# Smart AI Assistant - Deployment Script
# Run this script to deploy the upgraded Smart Assistant

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Smart AI Assistant - Deployment Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Clear all caches
Write-Host "[1/6] Clearing Laravel caches..." -ForegroundColor Yellow
php artisan cache:clear
php artisan view:clear
php artisan config:clear
php artisan route:clear
Write-Host "Done: Caches cleared" -ForegroundColor Green
Write-Host ""

# Step 2: Dump autoload
Write-Host "[2/6] Updating Composer autoload..." -ForegroundColor Yellow
composer dump-autoload
Write-Host "Done: Autoload updated" -ForegroundColor Green
Write-Host ""

# Step 3: Publish assets
Write-Host "[3/6] Publishing package assets..." -ForegroundColor Yellow
php artisan vendor:publish --tag=smart-ai-assistant-assets --force
Write-Host "Done: Assets published" -ForegroundColor Green
Write-Host ""

# Step 4: Publish views
Write-Host "[4/6] Publishing package views..." -ForegroundColor Yellow
php artisan vendor:publish --tag=smart-ai-assistant-views --force
Write-Host "Done: Views published" -ForegroundColor Green
Write-Host ""

# Step 5: Publish config
Write-Host "[5/6] Publishing package config..." -ForegroundColor Yellow
php artisan vendor:publish --tag=smart-ai-assistant-config --force
Write-Host "Done: Config published" -ForegroundColor Green
Write-Host ""

# Step 6: Final cache clear
Write-Host "[6/6] Final cache clear..." -ForegroundColor Yellow
php artisan cache:clear
php artisan view:clear
Write-Host "Done: Final caches cleared" -ForegroundColor Green
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Deployment Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "1. Hard refresh your browser" -ForegroundColor White
Write-Host "2. Open the Smart Assistant to test" -ForegroundColor White
Write-Host "3. Check browser console for errors" -ForegroundColor White
Write-Host ""
