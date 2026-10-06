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

# Step 4: Check for a customised view
# The widget is configured through config('smart-ai-assistant.widget') (branding, page scan,
# features), so views are no longer published. A customised copy left in
# resources/views/vendor/smart-ai-assistant hides every package view update.
Write-Host "[4/6] Checking for a customised widget view..." -ForegroundColor Yellow
if (Test-Path "resources/views/vendor/smart-ai-assistant") {
    Write-Host "Warning: resources/views/vendor/smart-ai-assistant exists and overrides the package view." -ForegroundColor Red
    Write-Host "         Move its branding into config('smart-ai-assistant.widget') and delete the folder." -ForegroundColor Red
} else {
    Write-Host "Done: Package view in use" -ForegroundColor Green
}
Write-Host ""

# Step 5: Publish config (only if missing)
# No --force: the published config holds host settings such as the auth middleware.
# Overwriting it would silently remove authentication from the assistant routes.
Write-Host "[5/6] Publishing package config (existing file is kept)..." -ForegroundColor Yellow
php artisan vendor:publish --tag=smart-ai-assistant-config
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
