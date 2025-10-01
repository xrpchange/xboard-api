# 🌐 Архитектура узлов XBoard

## 📋 Концепция

**XBoard работает как распределенная система узлов**, подобная почтовой системе:

- **Узлы (Nodes)** = Почтовые серверы (независимые доски объявлений)
- **XBoard Client** = Почтовый клиент (приложение, работающее с несколькими узлами)

Каждый узел - это независимый сервер с собственной базой данных и API, который предоставляет доску объявлений P2P обменов.

## 🏗️ Структура узлов

### Узел = Независимая доска объявлений

Каждый **узел** - это отдельный провайдер P2P обменов со своей доской объявлений.

**Текущий узел: XRPChange**

Один узел может иметь **несколько точек входа (endpoints)**:

#### **Точки входа узла XRPChange:**

**XBoard API (Доска объявлений):**
- `api.xrpchange.site` - Production с реальной криптой
- `sandbox.api.xrpchange.site` - Sandbox с тестовой криптой
- `dev.api.xrpchange.site` - Development для разработки

**Core API (Ядро блокчейна):**
- `api.core.xrpchange.site` - Production ядро с реальной криптой
- `sandbox.api.core.xrpchange.site` - Sandbox ядро с тестовой криптой

### Будущие узлы

В будущем могут появиться узлы от других провайдеров:
- `api.anotherexchange.com` - другая доска объявлений
- `api.cryptoexchange.io` - еще одна доска объявлений
- и т.д.

## 📊 Структура таблицы `nodes`

```sql
CREATE TABLE `nodes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `domain` VARCHAR(255) UNIQUE NOT NULL,
    `api_url` VARCHAR(255) NOT NULL,
    `country_code` VARCHAR(3) NULL,
    `owner` VARCHAR(255) NULL,
    `is_default` BOOLEAN DEFAULT FALSE,
    `is_required` BOOLEAN DEFAULT FALSE,
    `is_active` BOOLEAN DEFAULT TRUE,
    `priority` INT DEFAULT 0,
    `description` TEXT NULL,
    `endpoints` JSON NULL, -- ALL node entry points (production, sandbox, dev, core)
    `features` JSON NULL,
    `last_health_check` TIMESTAMP NULL,
    `health_status` ENUM('online', 'offline', 'degraded') DEFAULT 'online',
    `created_at` TIMESTAMP,
    `updated_at` TIMESTAMP
);
```

**Important:** Currencies are NOT stored in nodes! They are in a separate table `currencies`.

## 🔑 Key Fields

| Field | Description |
|-------|-------------|
| `name` | Node name (e.g., "XRPChange") |
| `domain` | Main node domain (e.g., "xrpchange.site") |
| `api_url` | Main API URL (e.g., "https://api.xrpchange.site") |
| `country_code` | Node location country code (ISO 3166-1 alpha-3) |
| `owner` | Node owner (organization/company) |
| `is_default` | Default node (shown first) |
| `is_required` | Required node (always connected, cannot be disabled) |
| `is_active` | Whether the node is active |
| `priority` | Sort priority (higher = more important) |
| `description` | Node description |
| `endpoints` | **ALL node entry points**: production_api, sandbox_api, dev_api, production_core, sandbox_core |
| `features` | Additional node capabilities |
| `health_status` | Availability status: `online`, `offline`, `degraded` |

**Important:** Currencies are stored in a separate `currencies` table, not in nodes!

## 🚀 API Endpoints

### Public (no authentication):

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/nodes` | GET | Get all active nodes (bulletin boards) |
| `/api/v1/nodes/required` | GET | Get required nodes |
| `/api/v1/nodes/{id}` | GET | Get node by ID |
| `/api/v1/nodes/{id}/health-check` | POST | Check node availability |

## 📱 Client Implementation

### On app startup:

1. **Load nodes** from API (`GET /api/v1/nodes`)
2. **Separate nodes**:
   - **Required** (`is_required = true`) - always connected
   - **Optional** - user can choose
3. **Check availability** of each node
4. **Save user settings** (which nodes are active)

### Usage example:

```kotlin
// Load nodes
val nodes = apiClient.getNodes()

// Required nodes (always active)
val requiredNodes = nodes.filter { it.is_required }

// Optional nodes (user can choose)
val optionalNodes = nodes.filter { !it.is_required }

// Check availability
nodes.forEach { node ->
    val isOnline = apiClient.checkNodeHealth(node.id)
}
```

## 🔒 XRPChange Node - Entry Points

### **Current System (single XRPChange node):**

The **XRPChange** node provides multiple entry points:

| Entry Point | Purpose | URL |
|-------------|---------|-----|
| Production API | Real crypto | `https://api.xrpchange.site` |
| Sandbox API | Test crypto | `https://sandbox.api.xrpchange.site` |
| Dev API | Development | `https://dev.api.xrpchange.site` |
| Production Core | Core with real crypto | `https://api.core.xrpchange.site` |
| Sandbox Core | Core with test crypto | `https://sandbox.api.core.xrpchange.site` |

**All these URLs are parts of ONE XRPChange node!**

### **Future Expansion (multiple nodes):**

When other providers appear:
- **Node 1**: XRPChange (with all its endpoints)
- **Node 2**: AnotherExchange (with its endpoints)
- **Node 3**: CryptoExchange (with its endpoints)

XBoard Client will be able to connect to all these nodes simultaneously.

## 💡 Architecture Advantages

1. **Scalability** - easy to add new nodes
2. **Fault tolerance** - if one node is unavailable, others continue working
3. **Flexibility** - user chooses nodes as desired
4. **Geographic distribution** - nodes in different countries
5. **Independence** - client is not tied to a specific server

## 🔄 Node Synchronization

Each node has:
- Its own database
- Its own listings
- Its own settings

Client can:
- Connect to multiple nodes simultaneously
- Search listings across all connected nodes
- Switch between nodes

## 🎯 Client Usage

### Required Nodes
- **Always connected** on startup
- **Cannot be disabled** in settings
- Provide basic functionality

### Optional Nodes
- **Connected by user choice**
- Extend app capabilities
- Can be in different countries/regions

## 📝 Node Configuration Example

```json
{
  "id": 1,
  "name": "XRPChange",
  "domain": "xrpchange.site",
  "api_url": "https://api.xrpchange.site",
  "country_code": "RUS",
  "owner": "XRPChange Team",
  "is_default": true,
  "is_required": true,
  "is_active": true,
  "priority": 100,
  "description": "XRPChange - main P2P cryptocurrency exchange node",
  "endpoints": {
    "production_api": "https://api.xrpchange.site",
    "sandbox_api": "https://sandbox.api.xrpchange.site",
    "dev_api": "https://dev.api.xrpchange.site",
    "production_core": "https://api.core.xrpchange.site",
    "sandbox_core": "https://sandbox.api.core.xrpchange.site"
  },
  "features": [
    "escrow_tokens",
    "exchange_lots",
    "buy_triggers",
    "transactions",
    "multi_crypto",
    "core_integration"
  ],
  "health_status": "online"
}
```

**Note:** Currencies are retrieved via a separate endpoint `/api/v1/currencies`

## 🛠️ Deployment

### Adding a new node:

1. **In DB** - add record to `nodes` table
2. **On server** - configure Nginx, SSL, domain
3. **In client** - will appear automatically after loading node list

### Migration:

```bash
php artisan migrate
php artisan db:seed --class=NodeSeeder
```

This will create the table and populate it with initial system nodes.

