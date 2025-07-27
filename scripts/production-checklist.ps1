# Production Checklist for payment-api
Write-Host "Production Checklist for payment-api" -ForegroundColor Cyan

$checksPassed = 0
$totalChecks = 8

function Test-Requirement([string]$Name, [bool]$Condition) {
    Write-Host "Checking: $Name" -ForegroundColor Yellow
    if ($Condition) {
        Write-Host "OK" -ForegroundColor Green
        $script:checksPassed++
    } else {
        Write-Host "MISSING" -ForegroundColor Red
    }
}

Test-Requirement "Composer" (Test-Path "composer.json")
Test-Requirement "Source Code" (Test-Path "src")
Test-Requirement "Docker" (Test-Path "Dockerfile")
Test-Requirement "Tests" (Test-Path "tests")
Test-Requirement "Documentation" (Test-Path "README.md")
Test-Requirement "Environment" (Test-Path ".env.example")
Test-Requirement "Kubernetes" (Test-Path "k8s")
Test-Requirement "Archive" ((Get-ChildItem -Name "payment-api-v1.0.0-production-certified-*.zip" -ErrorAction SilentlyContinue).Count -gt 0)

$percentage = [math]::Round(($checksPassed / $totalChecks) * 100, 0)
Write-Host "Compliance: $percentage% ($checksPassed/$totalChecks)" -ForegroundColor $(if($percentage -eq 100){"Green"}else{"Yellow"})

if ($checksPassed -eq $totalChecks) {
    Write-Host "CERTIFICATION COMPLETE!" -ForegroundColor Green
} else {
    Write-Host "Some requirements missing" -ForegroundColor Yellow
}

# Save results
$results = @{
    service = "payment-api"
    timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    compliance = $percentage
    certified = ($checksPassed -eq $totalChecks)
}

$results | ConvertTo-Json | Out-File "certification-results.json"
Write-Host "Results saved to certification-results.json" -ForegroundColor Gray
