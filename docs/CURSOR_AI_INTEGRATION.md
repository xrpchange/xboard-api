# Cursor AI Integration Guide для xboard-api

## 🎯 Overview

This guide explains how to use OpenAPI specifications with Cursor AI for the Laravel-based xboard-api project.

## 📋 Prerequisites

- **Cursor IDE** (latest version with MCP support)
- **Node.js** (v18+ recommended)
- **npm** or **npx** (comes with Node.js)
- **Laravel** project with `l5-swagger` installed

## 🚀 Quick Start

### 1. Generate OpenAPI Specification

The OpenAPI spec is automatically generated from PHP annotations using `l5-swagger`:

```bash
php artisan l5-swagger:generate
```

This creates/updates `docs/api_swagger.yaml` and `storage/api-docs/api-docs.json`.

### 2. Start MCP Server

**Windows (PowerShell):**
```powershell
cd C:\xampp\htdocs\Projects\xboard-api
.\scripts\start-mcp-server.ps1
```

**Linux/Mac (Bash):**
```bash
cd /path/to/xboard-api
chmod +x scripts/start-mcp-server.sh
./scripts/start-mcp-server.sh
```

**Manual:**
```bash
cd docs
npx @modelcontextprotocol/server-openapi --dir . --port 8766
```

### 3. Configure Cursor

1. Open Cursor Settings
2. Navigate to **Features → Model Context Protocol (MCP)**
3. Click **Add Server**
4. Enter endpoint: `http://localhost:8766`
5. Save settings

**Note:** xboard-core uses port 8765, xboard-api uses 8766. You can add both endpoints.

## 💡 Usage Examples

### Example 1: Generate Controller Method

**Prompt:**
```
Create a new endpoint for getting user profile using the OpenAPI spec
```

**Cursor will:**
- Use OpenAPI operationId patterns
- Generate proper Laravel controller method
- Include validation and response formatting

### Example 2: Add OpenAPI Annotation

**Prompt:**
```
Add OpenAPI annotation for this controller method with operationId
```

**Cursor will:**
- Generate proper `@OA\*` annotations
- Add operationId following naming conventions
- Include request/response schemas

### Example 3: Validate Request

**Prompt:**
```
Validate this request against the OpenAPI schema for createExchangeLot
```

**Cursor will:**
- Check request against OpenAPI schema
- Suggest Laravel validation rules
- Identify missing required fields

## 📁 File Structure

```
xboard-api/
├── docs/
│   ├── api_swagger.yaml          # Generated OpenAPI spec (from annotations)
│   ├── core_swagger.yaml          # Legacy spec (if exists)
│   ├── OPENAPI_3.1_MIGRATION.md   # Migration guide
│   └── CURSOR_AI_INTEGRATION.md   # This file
├── app/Http/Controllers/Api/
│   └── *.php                      # Controllers with @OA annotations
├── config/
│   └── l5-swagger.php             # l5-swagger configuration
├── scripts/
│   ├── start-mcp-server.ps1      # Windows MCP server script
│   └── start-mcp-server.sh       # Linux/Mac MCP server script
└── storage/api-docs/              # Generated API docs (JSON/YAML)
```

## 🔧 Configuration

### l5-swagger Configuration

**File:** `config/l5-swagger.php`

```php
'open_api_spec_version' => env('L5_SWAGGER_OPEN_API_SPEC_VERSION', '3.1.0'),
```

**Environment variable:**
```env
L5_SWAGGER_OPEN_API_SPEC_VERSION=3.1.0
```

### Adding operationId to Annotations

**Before:**
```php
/**
 * @OA\Post(
 *     path="/v1/auth/validate-token",
 *     summary="Validate escrow token",
 *     ...
 * )
 */
```

**After:**
```php
/**
 * @OA\Post(
 *     path="/v1/auth/validate-token",
 *     operationId="validateEscrowToken",  // ← Add this
 *     summary="Validate escrow token",
 *     ...
 * )
 */
```

## 📝 Best Practices

### 1. Always Include operationId

Every endpoint should have a unique `operationId`:
- Use camelCase
- Be descriptive (e.g., `createExchangeLot`, not `create`)
- Match controller method name when possible

### 2. Use Schema References

Instead of inline schemas, use `$ref`:

```php
/**
 * @OA\Response(
 *     response=200,
 *     @OA\JsonContent(ref="#/components/schemas/ExchangeLotResponse")
 * )
 */
```

### 3. Add Examples

Include examples in responses:

```php
/**
 * @OA\Response(
 *     response=200,
 *     @OA\JsonContent(
 *         example={
 *             "success": true,
 *             "data": {...}
 *         }
 *     )
 * )
 */
```

### 4. Regenerate After Changes

Always regenerate OpenAPI spec after updating annotations:

```bash
php artisan l5-swagger:generate
```

## 🐛 Troubleshooting

### OpenAPI Spec Not Updating

**Issue:** Changes in annotations not reflected in spec
- **Solution:** Run `php artisan l5-swagger:generate`
- **Check:** `storage/api-docs/` directory permissions

### MCP Server Not Starting

**Error:** `Port 8766 already in use`
- **Solution:** Use different port or stop existing server
- **Check:** `netstat -ano | findstr :8766` (Windows)

### Invalid OpenAPI Spec

**Error:** Validation errors in generated spec
- **Solution:** Run `npx @redocly/cli lint docs/api_swagger.yaml`
- **Fix:** Address validation errors in annotations

### Cursor Not Connecting

**Issue:** Cursor doesn't show autocomplete
- **Check:** MCP server is running (`http://localhost:8766`)
- **Check:** Cursor settings have correct endpoint
- **Check:** OpenAPI files are valid

## 🔗 Resources

- [L5-Swagger Documentation](https://github.com/DarkaOnLine/L5-Swagger)
- [OpenAPI 3.1 Specification](https://spec.openapis.org/oas/v3.1.0)
- [MCP OpenAPI Server](https://github.com/modelcontextprotocol/servers/tree/main/src/openapi)
- [Cursor AI Documentation](https://cursor.sh/docs)

## 📞 Support

For issues or questions:
- Check `OPENAPI_3.1_MIGRATION.md` for migration guide
- Review `.cursorrules` in workspace root
- Validate OpenAPI spec with Redocly CLI
