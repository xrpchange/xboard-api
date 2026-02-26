# 🌐 Nodes API Documentation

## 📋 Overview

The Nodes API provides endpoints for managing **exchange bulletin board nodes** and their endpoints. In partner and user-facing documentation we call:
- **Node** = **Обменник** (Exchange) — one independent exchange platform (one row in `nodes` table).
- **List of nodes** = **Сеть обменников** (Exchange network) — the set of trusted exchanges available to the client.

Each node (обменник) has multiple API endpoints (production, sandbox, dev_api, core_api) and role-based access control. See [TERMINOLOGY.md](TERMINOLOGY.md) for full partner terminology.

## 🏗️ Database Schema

### Nodes Table

```sql
CREATE TABLE `nodes` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `domain` varchar(255) NOT NULL,
    `api_url` varchar(255) NOT NULL,
    `country_code` varchar(10) DEFAULT NULL,
    `owner` varchar(255) DEFAULT NULL,
    `is_default` tinyint(1) NOT NULL DEFAULT '0',
    `is_required` tinyint(1) NOT NULL DEFAULT '0',
    `is_active` tinyint(1) NOT NULL DEFAULT '1',
    `priority` int(11) NOT NULL DEFAULT '0',
    `description` text DEFAULT NULL,
    `endpoints` json DEFAULT NULL,
    `endpoint_permissions` json DEFAULT NULL,
    `features` json DEFAULT NULL,
    `last_health_check` timestamp NULL DEFAULT NULL,
    `health_status` enum('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_domain` (`domain`),
    KEY `idx_active` (`is_active`),
    KEY `idx_required` (`is_required`),
    KEY `idx_priority` (`priority`)
);
```

### Endpoints JSON Structure

```json
{
  "production": {
    "name": "production",
    "url": "https://xdexboard.com/api/v1",
    "description": "Production API",
    "requires_auth": true,
    "is_primary": true
  },
  "sandbox": {
    "name": "sandbox", 
    "url": "https://sandbox.api.xdexboard.com/api/v1",
    "description": "Sandbox API",
    "requires_auth": true,
    "is_primary": false
  },
  "dev_api": {
    "name": "dev_api",
    "url": "https://dev.api.xdexboard.com/api/v1", 
    "description": "Development API",
    "requires_auth": true,
    "is_primary": false
  },
  "core_api": {
    "name": "core_api",
    "url": "https://api.core.xdexboard.com/api",
    "description": "Core API",
    "requires_auth": true,
    "is_primary": false
  }
}
```

### Endpoint Permissions JSON Structure

```json
{
  "production": ["public"],
  "sandbox": ["public"],
  "dev_api": ["public", "developer"],
  "core_api": ["public", "developer", "admin"]
}
```

## 🔌 API Endpoints

### Public Endpoints

#### GET /api/v1/nodes

Get all active nodes with filtered endpoints based on user role.

**Headers:**
- `X-User-Role` (optional): `public`, `developer`, `admin` (default: `public`)

**Response includes `meta.default_environment`:**
The response includes a `meta` field with `default_environment` that suggests the preferred mode to client applications:

```json
{
  "success": true,
  "data": [...],
  "meta": {
    "default_environment": "sandbox"  // or "production"
  }
}
```

**Purpose and Use Case:**
This is a "suggested mode" feature designed for project lifecycle management:

1. **Initial Launch (Testing Phase):**
   - Set `APP_DEFAULT_ENVIRONMENT=sandbox` in `.env` or `config/app.php`
   - Server returns `default_environment: "sandbox"` in response
   - Client app automatically enables Developer Mode and switches to Sandbox API
   - **Target:** Testers who won't manually configure settings - app configures itself automatically

2. **After Testing Phase (Production):**
   - Change `APP_DEFAULT_ENVIRONMENT=production` in `.env` or `config/app.php`
   - Server returns `default_environment: "production"` in response
   - Users who haven't manually configured settings will automatically switch to Production mode
   - **Target:** Regular users who won't explore settings - seamless transition to production

3. **Server Management vs User Control:**
   - **If user hasn't configured settings:** Server fully manages app mode (Developer Mode and API endpoint)
     - Server can switch from `sandbox` to `production` after testing phase - app will automatically follow
   - **If user has configured settings:** User's settings have priority, server suggestions are ignored
     - User manually enabled/disabled Developer Mode → server can't change it
     - User manually selected API endpoint → server can't change it
     - Once user configures any setting, server loses control over that setting

**Configuration:**
- Set `APP_DEFAULT_ENVIRONMENT` in `.env` file: `APP_DEFAULT_ENVIRONMENT=production` or `APP_DEFAULT_ENVIRONMENT=sandbox`
- Default value: `sandbox` (configured in `config/app.php`) - for initial testing phase
- **Migration:** After testing phase, change to `production` - users without manual settings will automatically switch

**How it works:**
- If `sandbox`: Client app automatically enables Developer Mode and switches to Sandbox API endpoint (only if user hasn't configured settings)
- If `production`: Client app uses Production mode by default (Developer Mode disabled, only if user hasn't configured settings)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "XRPChange",
      "domain": "xdexboard.com",
      "api_url": "https://xdexboard.com/api/v1",
      "country_code": null,
      "owner": "XRPChange Team",
      "is_default": true,
      "is_required": true,
      "is_active": true,
      "priority": 0,
      "description": "Main XRPChange exchange node",
      "endpoints": [
        {
          "name": "production",
          "url": "https://xdexboard.com/api/v1",
          "description": "Production API",
          "requires_auth": true,
          "is_primary": true
        },
        {
          "name": "sandbox",
          "url": "https://sandbox.api.xdexboard.com/api/v1",
          "description": "Sandbox API", 
          "requires_auth": true,
          "is_primary": false
        }
      ],
      "features": ["escrow_tokens", "exchange_lots", "p2p"],
      "last_health_check": "2024-01-15T10:30:00Z",
      "health_status": "online",
      "created_at": "2024-01-01T00:00:00Z",
      "updated_at": "2024-01-15T10:30:00Z"
    }
  ]
}
```

#### GET /api/v1/nodes/required

Get only required nodes (is_required = true).

**Headers:**
- `X-User-Role` (optional): `public`, `developer`, `admin` (default: `public`)

**Response:** Same format as `/api/v1/nodes` but only required nodes.

#### GET /api/v1/nodes/{id}

Get specific node by ID.

**Parameters:**
- `id` (path): Node ID

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "XRPChange",
    "domain": "xdexboard.com",
    // ... full node object
  }
}
```

#### POST /api/v1/nodes/{id}/health-check

Check health status of specific node.

**Parameters:**
- `id` (path): Node ID

**Response:**
```json
{
  "success": true,
  "data": {
    "node_id": 1,
    "status": "online",
    "checked_at": "2024-01-15T10:30:00Z",
    "is_online": true
  }
}
```

### Admin Endpoints

All admin endpoints require authentication with admin role.

#### POST /api/v1/admin/nodes

Create new node.

**Headers:**
- `Authorization: Bearer {token}` (admin role required)

**Request Body:**
```json
{
  "name": "New Exchange",
  "domain": "newexchange.com",
  "api_url": "https://newexchange.com/api/v1",
  "country_code": "US",
  "owner": "New Exchange Team",
  "is_default": false,
  "is_required": false,
  "priority": 10,
  "description": "New exchange node",
  "endpoints": {
    "production": {
      "name": "production",
      "url": "https://newexchange.com/api/v1",
      "description": "Production API",
      "requires_auth": true,
      "is_primary": true
    }
  },
  "endpoint_permissions": {
    "production": ["public"]
  },
  "features": ["exchange_lots"]
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 2,
    "name": "New Exchange",
    // ... created node object
  }
}
```

#### PUT /api/v1/admin/nodes/{id}

Update existing node.

**Headers:**
- `Authorization: Bearer {token}` (admin role required)

**Parameters:**
- `id` (path): Node ID

**Request Body:** Same as create, all fields optional.

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 2,
    "name": "Updated Exchange",
    // ... updated node object
  }
}
```

#### DELETE /api/v1/admin/nodes/{id}

Delete node.

**Headers:**
- `Authorization: Bearer {token}` (admin role required)

**Parameters:**
- `id` (path): Node ID

**Response:**
```json
{
  "success": true,
  "message": "Node deleted successfully"
}
```

#### POST /api/v1/admin/nodes/{id}/toggle

Toggle node active status.

**Headers:**
- `Authorization: Bearer {token}` (admin role required)

**Parameters:**
- `id` (path): Node ID

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "is_active": false,
    "message": "Node toggled successfully"
  }
}
```

#### POST /api/v1/admin/nodes/{id}/set-default

Set node as default.

**Headers:**
- `Authorization: Bearer {token}` (admin role required)

**Parameters:**
- `id` (path): Node ID

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "is_default": true,
    "message": "Node set as default"
  }
}
```

#### PUT /api/v1/admin/nodes/{id}/endpoints

Update node endpoints.

**Headers:**
- `Authorization: Bearer {token}` (admin role required)

**Parameters:**
- `id` (path): Node ID

**Request Body:**
```json
{
  "endpoints": {
    "production": {
      "name": "production",
      "url": "https://updated.xdexboard.com/api/v1",
      "description": "Updated Production API",
      "requires_auth": true,
      "is_primary": true
    },
    "sandbox": {
      "name": "sandbox",
      "url": "https://sandbox.updated.com/api/v1",
      "description": "Updated Sandbox API",
      "requires_auth": true,
      "is_primary": false
    }
  },
  "endpoint_permissions": {
    "production": ["public"],
    "sandbox": ["public"]
  }
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "endpoints": {
      // ... updated endpoints
    },
    "message": "Endpoints updated successfully"
  }
}
```

## 🔐 Authentication & Authorization

### User Roles

1. **Public**: Access to production and sandbox endpoints
2. **Developer**: Access to dev_api endpoints (additional to public)
3. **Admin**: Access to core_api endpoints (additional to developer)

### Role-Based Endpoint Filtering

The API automatically filters endpoints based on the `X-User-Role` header:

```php
public function getAccessibleEndpoints(string $userRole = 'public'): array
{
    $accessibleEndpoints = [];
    
    foreach ($this->endpoints as $endpointName => $endpoint) {
        $permissions = $this->endpoint_permissions[$endpointName] ?? ['public'];
        
        if (in_array($userRole, $permissions)) {
            $accessibleEndpoints[$endpointName] = $endpoint;
        }
    }
    
    return $accessibleEndpoints;
}
```

### Middleware

- **AdminAccess**: Ensures user has admin role for admin endpoints

## 📊 Health Monitoring

### Health Check Implementation

```php
public function checkHealth(): bool
{
    try {
        // Simple HTTP request to node's health endpoint
        $response = Http::timeout(5)->get($this->api_url . '/health');
        
        if ($response->successful()) {
            $this->update(['health_status' => 'online']);
            return true;
        } else {
            $this->update(['health_status' => 'offline']);
            return false;
        }
    } catch (Exception $e) {
        $this->update(['health_status' => 'offline']);
        return false;
    }
}
```

### Health Status Values

- `online`: Node is responding to health checks
- `offline`: Node is not responding
- `degraded`: Node is responding but with issues
- `unknown`: Health status not yet checked

## 🚀 Usage Examples

### Get All Nodes (Public)

```bash
curl -X GET "https://api.xdexboard.com/api/v1/nodes" \
  -H "X-User-Role: public"
```

### Get All Nodes (Developer)

```bash
curl -X GET "https://api.xdexboard.com/api/v1/nodes" \
  -H "X-User-Role: developer"
```

### Check Node Health

```bash
curl -X POST "https://api.xdexboard.com/api/v1/nodes/1/health-check"
```

### Create New Node (Admin)

```bash
curl -X POST "https://api.xdexboard.com/api/v1/admin/nodes" \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New Exchange",
    "domain": "newexchange.com",
    "api_url": "https://newexchange.com/api/v1",
    "endpoints": {
      "production": {
        "name": "production",
        "url": "https://newexchange.com/api/v1",
        "description": "Production API",
        "requires_auth": true,
        "is_primary": true
      }
    },
    "endpoint_permissions": {
      "production": ["public"]
    }
  }'
```

## 🔧 Configuration

### Environment Variables

```env
# Node configuration
NODE_DOMAIN=api.xdexboard.com

# Database configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xboard_api
DB_USERNAME=root
DB_PASSWORD=
```

### Seeder Configuration

The `NodeSeeder` creates the default XRPChange node with all endpoints:

```php
public function run(): void
{
    Node::create([
        'name' => 'XRPChange',
        'domain' => 'xdexboard.com',
        'api_url' => 'https://xdexboard.com/api/v1',
        'is_default' => true,
        'is_required' => true,
        'priority' => 0,
        'description' => 'Main XRPChange exchange node',
        'endpoints' => [
            'production' => [
                'name' => 'production',
                'url' => 'https://xdexboard.com/api/v1',
                'description' => 'Production API',
                'requires_auth' => true,
                'is_primary' => true
            ],
            // ... other endpoints
        ],
        'endpoint_permissions' => [
            'production' => ['public'],
            'sandbox' => ['public'],
            'dev_api' => ['public', 'developer'],
            'core_api' => ['public', 'developer', 'admin']
        ],
        'features' => ['escrow_tokens', 'exchange_lots', 'p2p']
    ]);
}
```

## 🐛 Error Handling

### Common Error Responses

```json
{
  "success": false,
  "error": "Node not found",
  "error_code": "NODE_NOT_FOUND"
}
```

### Error Codes

- `NODE_NOT_FOUND`: Node with specified ID not found
- `UNAUTHORIZED`: Invalid or missing authentication
- `FORBIDDEN`: Insufficient permissions for operation
- `VALIDATION_ERROR`: Invalid request data
- `HEALTH_CHECK_FAILED`: Node health check failed

## 📈 Performance Considerations

1. **Caching**: Consider caching node data for better performance
2. **Health Checks**: Implement background health checking
3. **Load Balancing**: Use node priority for load balancing
4. **Rate Limiting**: Implement rate limiting for health checks
5. **Monitoring**: Monitor API performance and node availability

## 🔒 Security Best Practices

1. **Role Validation**: Always validate user roles for endpoint access
2. **Input Validation**: Validate all input data
3. **SQL Injection**: Use Eloquent ORM to prevent SQL injection
4. **Rate Limiting**: Implement rate limiting for admin endpoints
5. **Audit Logging**: Log all admin operations
6. **HTTPS Only**: Ensure all API communications use HTTPS

## 📚 Related Documentation

- [API Authentication](./API_AUTHENTICATION.md)
- [Admin API](./ADMIN_API.md)
- [Error Handling](./ERROR_HANDLING.md)
- [Database Schema](./DATABASE_SCHEMA.md)


