# Dev Server Configuration

## Overview

`dev.api.xrpchange.site` is your local development server that can be configured to work in either sandbox or production mode.

## Configuration

### Environment Variables

Add to your `.env` file:

```env
# Dev server mode (sandbox or production)
# Default: sandbox
DEV_MODE=sandbox
```

### Modes

#### Sandbox Mode (Default)
```env
DEV_MODE=sandbox
```
- Uses sandbox core: `https://sandbox.api.core.xrpchange.site`
- Safe for testing

#### Production Mode
```env
DEV_MODE=production
```
- Uses production core: `https://api.core.xrpchange.site`
- Use with caution

## Detection

The system automatically detects dev mode when:
1. `APP_ENV=local` in your environment
2. Hostname contains `dev.api.xrpchange.site`

## Usage

### Default Behavior
- Dev server works like sandbox by default
- No additional configuration needed

### Switch to Production Mode
```env
DEV_MODE=production
```

### Switch Back to Sandbox
```env
DEV_MODE=sandbox
```

## Logging

The system logs which mode is active:
```
[INFO] Checking token balance: {"core_url":"https://sandbox.api.core.xrpchange.site","environment":"dev-sandbox"}
```

or

```
[INFO] Checking token balance: {"core_url":"https://api.core.xrpchange.site","environment":"dev-production"}
```
