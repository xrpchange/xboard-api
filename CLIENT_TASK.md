# XBoard Client - Kotlin Multiplatform Application

## Project Overview

XBoard Client is a Kotlin Multiplatform application for the XBoard P2P Cryptocurrency Exchange system. The client provides access to the exchange board through a mobile application for Android and web application.

## Architecture

### Technology Stack
- **Kotlin Multiplatform** - shared business logic
- **Jetpack Compose** - modern declarative UI
- **Ktor Client** - HTTP client for API calls
- **Kotlinx Serialization** - JSON serialization
- **Coroutines** - asynchronous programming

### Platforms
- **Android** - native mobile application
- **Web** - browser-based application
- **Desktop** - future support (optional)

## API Integration

### Base URLs
- **Sandbox**: `https://sandbox.api.xrpchange.site/api/v1`
- **Production**: `https://api.xrpchange.site/api/v1`

### Authentication System
1. **Collateral Token** - user enters escrow token (XRP-based)
2. **Bearer Token** - obtained from API using collateral token
3. **Session Management** - token validation and refresh

### Key API Endpoints
- `POST /auth/bearer-token` - get bearer token from collateral token
- `GET /auth/validate` - validate current bearer token
- `GET /countries` - get list of countries
- `GET /currencies/fiat` - get fiat currencies
- `GET /currencies/crypto` - get crypto currencies
- `GET /currencies/all` - get all currencies

## Core Features

### 1. Authentication
- **Collateral Token Input** - secure token entry
- **Test Mode Toggle** - switch between sandbox and production
- **Test Group** - required in sandbox mode for data isolation
- **Token Validation** - automatic session management

### 2. Main Screens
- **Login Screen** - collateral token authentication
- **Home Screen** - main navigation hub
- **Exchange Board** - view and create exchange lots
- **Buy Triggers** - manage automatic purchase triggers
- **Transactions** - view transaction history

### 3. Reference Data
- **Countries** - ISO 3166-1 alpha-3 codes
- **Fiat Currencies** - ISO 4217 codes
- **Crypto Currencies** - major cryptocurrencies + stablecoins
- **Banks** - linked to countries and currencies

## Project Structure

```
xboard-client/
├── src/
│   ├── commonMain/kotlin/     # Shared code
│   │   ├── data/              # API, models, repositories
│   │   ├── domain/            # Use cases, business logic
│   │   └── presentation/      # UI screens, themes
│   ├── androidMain/kotlin/    # Android-specific code
│   └── jsMain/kotlin/         # Web-specific code
├── build.gradle.kts           # Build configuration
└── settings.gradle.kts        # Project settings
```

## Data Models

### Authentication
- `TokenRequest` - collateral token + test group
- `TokenResponse` - bearer token + expiration
- `UserInfo` - device info + last used

### Reference Data
- `Country` - country information
- `FiatCurrency` - fiat currency data
- `CryptoCurrency` - cryptocurrency data

### API Response
- `ApiResponse<T>` - generic API response wrapper

## UI Design

### Theme
- **Material Design 3** - modern design system
- **Light/Dark Mode** - system theme support
- **XBoard Branding** - custom color scheme

### Screens
- **Login** - clean authentication interface
- **Home** - card-based navigation
- **Settings** - app preferences

## Development Setup

### Prerequisites
- Android Studio Arctic Fox or newer
- JDK 11+
- Kotlin 1.9.20+

### Building
1. Open project in Android Studio
2. Sync Gradle files
3. Run on Android device/emulator
4. For web: `./gradlew jsBrowserDevelopmentRun`

## Integration with XBoard Backend

### Core System Integration
- **XRPChangeCore** - separate service for critical operations
- **Collateral Tokens** - XRP-based user identification
- **Sandbox Support** - test mode with data isolation

### Security Features
- **Bearer Token Authentication** - secure API access
- **Test Group Isolation** - sandbox data separation
- **Encrypted Communication** - HTTPS only

## Future Enhancements

### Planned Features
- **Push Notifications** - collateral token alerts
- **Offline Support** - cached reference data
- **Biometric Authentication** - secure token storage
- **Multi-language Support** - internationalization

### Platform Expansion
- **iOS Support** - native iOS application
- **Desktop Support** - cross-platform desktop app
- **PWA Support** - progressive web app features

## Testing

### Test Strategy
- **Unit Tests** - business logic testing
- **UI Tests** - Compose UI testing
- **Integration Tests** - API integration testing
- **Sandbox Testing** - test mode validation

### Test Data
- Use sandbox environment for development
- Test groups for data isolation
- Mock API responses for offline testing

## Deployment

### Android
- Build APK/AAB for distribution
- Google Play Store deployment
- Internal testing tracks

### Web
- Static hosting deployment
- CDN distribution
- Progressive Web App features

## Documentation

### API Documentation
- Swagger/OpenAPI specification
- Endpoint documentation
- Authentication examples

### User Documentation
- App usage guide
- Authentication flow
- Feature explanations

## Support

### Development
- Kotlin Multiplatform documentation
- Compose Multiplatform guides
- Ktor Client examples

### Backend Integration
- XBoard API documentation
- Core system integration
- Sandbox testing procedures
