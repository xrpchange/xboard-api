#!/bin/bash
# Bash script to start MCP OpenAPI server for Cursor AI
# This server provides OpenAPI specifications to Cursor via Model Context Protocol

echo "🚀 Starting MCP OpenAPI Server for Cursor AI (xboard-api)..."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCS_DIR="$SCRIPT_DIR/../docs"
CORE_OPENAPI_DIR="$SCRIPT_DIR/../../xboard-core/openapi"

# Check if docs directory exists
if [ ! -d "$DOCS_DIR" ]; then
    echo "❌ Error: Docs directory not found: $DOCS_DIR"
    exit 1
fi

# Check if Node.js is available
if ! command -v node &> /dev/null; then
    echo "❌ Error: Node.js not found. Please install Node.js first."
    exit 1
fi

echo "✅ Node.js version: $(node --version)"

# Check if npx is available
if ! command -v npx &> /dev/null; then
    echo "❌ Error: npx not found. Please install npm first."
    exit 1
fi

echo "✅ npx version: $(npx --version)"
echo ""
echo "📁 Docs directory: $DOCS_DIR"
echo "📁 Core OpenAPI directory: $CORE_OPENAPI_DIR"
echo ""

# Create combined directory for MCP server (if needed)
MCP_DIR="$SCRIPT_DIR/../openapi-mcp"
mkdir -p "$MCP_DIR"
echo "📂 MCP directory: $MCP_DIR"

# Copy OpenAPI files to MCP directory
if [ -d "$DOCS_DIR" ]; then
    cp "$DOCS_DIR"/*.yaml "$MCP_DIR/" 2>/dev/null
    echo "✅ Copied xboard-api OpenAPI specs"
fi

if [ -d "$CORE_OPENAPI_DIR" ]; then
    cp "$CORE_OPENAPI_DIR"/*.yaml "$MCP_DIR/" 2>/dev/null
    echo "✅ Copied xboard-core OpenAPI specs"
fi

echo ""
echo "🌐 Starting MCP server on port 8766..."
echo "   Cursor AI will connect to: http://localhost:8766"
echo ""
echo "📝 To configure Cursor:"
echo "   1. Open Cursor Settings"
echo "   2. Go to Features → Model Context Protocol (MCP)"
echo "   3. Add endpoint: http://localhost:8766"
echo ""
echo "💡 Note: xboard-core uses port 8765, xboard-api uses 8766"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""

# Start MCP server
cd "$MCP_DIR" || exit 1
npx --yes @modelcontextprotocol/server-openapi --dir . --port 8766
