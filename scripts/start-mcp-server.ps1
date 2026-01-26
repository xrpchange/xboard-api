# PowerShell script to start MCP OpenAPI server for Cursor AI
# This server provides OpenAPI specifications to Cursor via Model Context Protocol

Write-Host "🚀 Starting MCP OpenAPI Server for Cursor AI (xboard-api)..." -ForegroundColor Green

$docsDir = Join-Path $PSScriptRoot "..\docs"
$coreOpenapiDir = Join-Path $PSScriptRoot "..\..\xboard-core\openapi"

# Check if docs directory exists
if (-not (Test-Path $docsDir)) {
    Write-Host "❌ Error: Docs directory not found: $docsDir" -ForegroundColor Red
    exit 1
}

# Check if Node.js is available
try {
    $nodeVersion = node --version
    Write-Host "✅ Node.js version: $nodeVersion" -ForegroundColor Green
} catch {
    Write-Host "❌ Error: Node.js not found. Please install Node.js first." -ForegroundColor Red
    exit 1
}

# Check if npx is available
try {
    $npxVersion = npx --version
    Write-Host "✅ npx version: $npxVersion" -ForegroundColor Green
} catch {
    Write-Host "❌ Error: npx not found. Please install npm first." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "📁 Docs directory: $docsDir" -ForegroundColor Cyan
Write-Host "📁 Core OpenAPI directory: $coreOpenapiDir" -ForegroundColor Cyan
Write-Host ""

# Create combined directory for MCP server (if needed)
$mcpDir = Join-Path $PSScriptRoot "..\openapi-mcp"
if (-not (Test-Path $mcpDir)) {
    New-Item -ItemType Directory -Path $mcpDir | Out-Null
    Write-Host "📂 Created MCP directory: $mcpDir" -ForegroundColor Yellow
}

# Copy OpenAPI files to MCP directory
if (Test-Path $docsDir) {
    Get-ChildItem -Path $docsDir -Filter "*.yaml" | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $mcpDir -Force
        Write-Host "✅ Copied: $($_.Name)" -ForegroundColor Green
    }
}

if (Test-Path $coreOpenapiDir) {
    Get-ChildItem -Path $coreOpenapiDir -Filter "*.yaml" | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $mcpDir -Force
        Write-Host "✅ Copied: $($_.Name) (from xboard-core)" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "🌐 Starting MCP server on port 8766..." -ForegroundColor Cyan
Write-Host "   Cursor AI will connect to: http://localhost:8766" -ForegroundColor Gray
Write-Host ""
Write-Host "📝 To configure Cursor:" -ForegroundColor Yellow
Write-Host "   1. Open Cursor Settings" -ForegroundColor Gray
Write-Host "   2. Go to Features → Model Context Protocol (MCP)" -ForegroundColor Gray
Write-Host "   3. Add endpoint: http://localhost:8766" -ForegroundColor Gray
Write-Host ""
Write-Host "💡 Note: xboard-core uses port 8765, xboard-api uses 8766" -ForegroundColor Yellow
Write-Host ""
Write-Host "Press Ctrl+C to stop the server" -ForegroundColor Yellow
Write-Host ""

# Start MCP server
try {
    Push-Location $mcpDir
    npx --yes @modelcontextprotocol/server-openapi --dir . --port 8766
} catch {
    Write-Host "❌ Error starting MCP server: $_" -ForegroundColor Red
    exit 1
} finally {
    Pop-Location
}
