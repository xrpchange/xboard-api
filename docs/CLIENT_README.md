# XBoard Client - Android application in Kotlin

## 🤖 Section for AI

**Project Context**: XBoard is a P2P cryptocurrency exchange with collateral guarantee, using Ripple (XRP) for collateral tokens. The client application should provide simple and secure access to the API.

**Key Features**:
- Authentication via collateral tokens (no registration)
- Sandbox support for testing
- P2P exchange board operation
- Buy trigger management
- Transaction tracking

**Technical Requirements**:
- Kotlin + Android SDK
- Retrofit for HTTP requests
- Kotlinx Serialization for JSON
- Coroutines for asynchronicity
- Material Design 3

**API Endpoints**: See [API documentation](../README.md#api-documentation)

---

## Project Description

XBoard Client is an Android application for the P2P cryptocurrency exchange XBoard. The application allows users to exchange cryptocurrencies through collateral tokens without the need for registration.

### Main Features

- 🔐 **Authentication via collateral tokens** - no registration, token = access
- 🏪 **Exchange board** - view and create exchange lots
- 🎯 **Buy triggers** - automatic purchases when conditions are met
- 📊 **Transactions** - track operation history
- 🧪 **Sandbox** - test mode for safe experimentation

## Quick Start

### Requirements

- Android Studio Arctic Fox or newer
- Android SDK 24+ (Android 7.0)
- Kotlin 1.8+
- JDK 11+

### Installation

1. **Clone repository**
```bash
git clone https://github.com/xrpchange/xboard-client.git
cd xboard-client
```

2. **Open in Android Studio**
```bash
# Open project in Android Studio
# Sync Gradle files
```

3. **Environment setup**
```kotlin
// In build.gradle (app)
android {
    buildTypes {
        debug {
            buildConfigField "String", "API_BASE_URL", "\"http://sandbox.api.xdexboard.com\""
        }
        release {
            buildConfigField "String", "API_BASE_URL", "\"https://api.xdexboard.com\""
        }
    }
}
```

## Architecture

### Project Structure

```
app/
├── src/main/java/com/xdexboard/xboard/
│   ├── data/
│   │   ├── api/           # Retrofit API interfaces
│   │   ├── models/        # Data classes
│   │   └── repository/    # Repositories
│   ├── domain/
│   │   ├── models/        # Domain models
│   │   └── usecases/      # Use cases
│   ├── presentation/
│   │   ├── ui/           # UI components
│   │   ├── viewmodels/   # ViewModels
│   │   └── navigation/   # Navigation
│   └── di/               # Dependency Injection
├── src/main/res/
│   ├── layout/           # XML layouts
│   ├── values/           # Strings, colors, themes
│   └── drawable/         # Icons, images
└── build.gradle
```

### Technology Stack

- **UI**: Jetpack Compose + Material Design 3
- **Architecture**: MVVM + Clean Architecture
- **Network**: Retrofit + OkHttp
- **Serialization**: Kotlinx Serialization
- **Asynchronicity**: Coroutines + Flow
- **DI**: Hilt
- **Navigation**: Navigation Compose
- **Local Storage**: DataStore

## API Integration

### Retrofit Configuration

```kotlin
@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {
    
    @Provides
    @Singleton
    fun provideOkHttpClient(): OkHttpClient {
        return OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor())
            .addInterceptor(HttpLoggingInterceptor().apply {
                level = if (BuildConfig.DEBUG) {
                    HttpLoggingInterceptor.Level.BODY
                } else {
                    HttpLoggingInterceptor.Level.NONE
                }
            })
            .build()
    }
    
    @Provides
    @Singleton
    fun provideRetrofit(okHttpClient: OkHttpClient): Retrofit {
        return Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(okHttpClient)
            .addConverterFactory(Json.asConverterFactory("application/json".toMediaType()))
            .build()
    }
}
```

### API Interfaces

```kotlin
interface XBoardApi {
    
    @POST("v1/auth/validate-token")
    suspend fun validateEscrowToken(
        @Body request: EscrowTokenValidationRequest
    ): Response<EscrowTokenValidationResponse>
    
    @GET("v1/countries")
    suspend fun getCountries(): Response<List<Country>>
    
    @GET("v1/currencies/fiat")
    suspend fun getFiatCurrencies(): Response<List<FiatCurrency>>
    
    @GET("v1/currencies/crypto")
    suspend fun getCryptoCurrencies(): Response<List<CryptoCurrency>>
    
    @GET("v1/banks")
    suspend fun getBanks(): Response<List<Bank>>
    
    @GET("v1/exchange-lots")
    suspend fun getExchangeLots(): Response<List<ExchangeLot>>
    
    @POST("v1/exchange-lots")
    suspend fun createExchangeLot(
        @Body request: CreateExchangeLotRequest
    ): Response<ExchangeLot>
    
    @GET("v1/buy-triggers")
    suspend fun getBuyTriggers(): Response<List<BuyTrigger>>
    
    @POST("v1/buy-triggers")
    suspend fun createBuyTrigger(
        @Body request: CreateBuyTriggerRequest
    ): Response<BuyTrigger>
    
    @GET("v1/transactions")
    suspend fun getTransactions(): Response<List<Transaction>>
    
    @GET("v1/transactions/stats")
    suspend fun getTransactionStats(): Response<TransactionStats>
}
```

### Модели данных

```kotlin
@Serializable
data class EscrowTokenValidationRequest(
    @SerialName("escrow_token")
    val escrowToken: String, // publicPart JSON string
    @SerialName("device_id")
    val deviceId: String? = null
)

@Serializable
data class EscrowTokenValidationResponse(
    val success: Boolean,
    val data: EscrowTokenValidationData?
)

@Serializable
data class EscrowTokenValidationData(
    val valid: Boolean,
    @SerialName("wallet_address")
    val walletAddress: String,
    @SerialName("crypto_currency")
    val cryptoCurrency: String,
    @SerialName("node_domain")
    val nodeDomain: String,
    val environment: String
)

@Serializable
data class Country(
    val id: Int,
    val code: String,
    val name: String,
    val flag: String
)

@Serializable
data class ExchangeLot(
    val id: Int,
    @SerialName("wallet_address")
    val walletAddress: String,
    @SerialName("crypto_currency_id")
    val cryptoCurrencyId: Int,
    @SerialName("fiat_currency_id")
    val fiatCurrencyId: Int,
    @SerialName("bank_id")
    val bankId: Int,
    val amount: Double,
    val price: Double,
    @SerialName("is_test")
    val isTest: Boolean,
    @SerialName("test_group")
    val testGroup: String?,
    @SerialName("created_at")
    val createdAt: String,
    @SerialName("updated_at")
    val updatedAt: String
)
```

## Аутентификация

### Управление токенами

```kotlin
class AuthManager @Inject constructor(
    private val dataStore: DataStore<Preferences>
) {
    
    companion object {
        private val ESCROW_TOKEN_PUBLIC_PART = stringPreferencesKey("escrow_token_public_part")
        private val WALLET_ADDRESS = stringPreferencesKey("wallet_address")
        private val CRYPTO_CURRENCY = stringPreferencesKey("crypto_currency")
    }
    
    suspend fun saveAuthData(
        escrowTokenPublicPart: String,
        walletAddress: String,
        cryptoCurrency: String
    ) {
        dataStore.edit { preferences ->
            preferences[ESCROW_TOKEN_PUBLIC_PART] = escrowTokenPublicPart
            preferences[WALLET_ADDRESS] = walletAddress
            preferences[CRYPTO_CURRENCY] = cryptoCurrency
        }
    }
    
    suspend fun getEscrowTokenPublicPart(): String? {
        return dataStore.data.first()[ESCROW_TOKEN_PUBLIC_PART]
    }
    
    suspend fun clearAuthData() {
        dataStore.edit { preferences ->
            preferences.remove(ESCROW_TOKEN_PUBLIC_PART)
            preferences.remove(WALLET_ADDRESS)
            preferences.remove(CRYPTO_CURRENCY)
        }
    }
}
```

### Interceptor для авторизации

```kotlin
class AuthInterceptor @Inject constructor(
    private val authManager: AuthManager
) : Interceptor {
    
    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        
        // Не добавляем токен для публичных эндпоинтов
        if (isPublicEndpoint(request.url.encodedPath)) {
            return chain.proceed(request)
        }
        
        val escrowTokenPublicPart = runBlocking { authManager.getEscrowTokenPublicPart() }
        
        return if (escrowTokenPublicPart != null) {
            val authenticatedRequest = request.newBuilder()
                .addHeader("X-Escrow-Token", escrowTokenPublicPart)
                .build()
            chain.proceed(authenticatedRequest)
        } else {
            chain.proceed(request)
        }
    }
    
    private fun isPublicEndpoint(path: String): Boolean {
        val publicEndpoints = listOf(
            "/v1/auth/validate-token",
            "/v1/countries",
            "/v1/currencies/fiat",
            "/v1/currencies/crypto",
            "/v1/banks"
        )
        return publicEndpoints.any { path.contains(it) }
    }
}
```

## UI Компоненты

### Главный экран

```kotlin
@Composable
fun MainScreen(
    viewModel: MainViewModel = hiltViewModel()
) {
    val uiState by viewModel.uiState.collectAsState()
    
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("XBoard") },
                actions = {
                    if (uiState.isAuthenticated) {
                        IconButton(onClick = { viewModel.logout() }) {
                            Icon(Icons.Default.ExitToApp, contentDescription = "Выход")
                        }
                    }
                }
            )
        }
    ) { paddingValues ->
        when {
            !uiState.isAuthenticated -> {
                AuthScreen(
                    onAuthSuccess = { escrowTokenPublicPart, walletAddress, cryptoCurrency ->
                        viewModel.saveAuthData(escrowTokenPublicPart, walletAddress, cryptoCurrency)
                    }
                )
            }
            uiState.isLoading -> {
                Box(
                    modifier = Modifier.fillMaxSize(),
                    contentAlignment = Alignment.Center
                ) {
                    CircularProgressIndicator()
                }
            }
            else -> {
                ExchangeLotsScreen(
                    modifier = Modifier.padding(paddingValues)
                )
            }
        }
    }
}
```

### Экран аутентификации

```kotlin
@Composable
fun AuthScreen(
    onAuthSuccess: (String, String, String) -> Unit
) {
    var escrowToken by remember { mutableStateOf("") }
    var isLoading by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Text(
            text = "XBoard",
            style = MaterialTheme.typography.headlineLarge,
            modifier = Modifier.padding(bottom = 32.dp)
        )
        
        OutlinedTextField(
            value = escrowToken,
            onValueChange = { escrowToken = it },
            label = { Text("Залоговый токен") },
            modifier = Modifier.fillMaxWidth(),
            enabled = !isLoading
        )
        
        if (errorMessage != null) {
            Text(
                text = errorMessage!!,
                color = MaterialTheme.colorScheme.error,
                modifier = Modifier.padding(top = 8.dp)
            )
        }
        
        Button(
            onClick = {
                if (escrowToken.isNotBlank()) {
                    isLoading = true
                    // Использование escrow token publicPart в заголовке X-Escrow-Token
                }
            },
            modifier = Modifier
                .fillMaxWidth()
                .padding(top = 16.dp),
            enabled = !isLoading && escrowToken.isNotBlank()
        ) {
            if (isLoading) {
                CircularProgressIndicator(
                    modifier = Modifier.size(16.dp),
                    color = MaterialTheme.colorScheme.onPrimary
                )
            } else {
                Text("Войти")
            }
        }
    }
}
```

## Тестирование

### Unit тесты

```kotlin
class AuthManagerTest {
    
    @Test
    fun `saveAuthData should store data correctly`() = runTest {
        // Given
        val authManager = AuthManager(testDataStore)
        val escrowTokenPublicPart = "{\"version\":\"1.0\",\"wallet_address\":\"r123...\",\"currency\":\"XRP\"}"
        val walletAddress = "test_wallet"
        val cryptoCurrency = "XRP"
        
        // When
        authManager.saveAuthData(escrowTokenPublicPart, walletAddress, cryptoCurrency)
        
        // Then
        val savedPublicPart = authManager.getEscrowTokenPublicPart()
        assertEquals(escrowTokenPublicPart, savedPublicPart)
    }
}
```

### UI тесты

```kotlin
@Test
fun authScreen_displaysCorrectly() {
    composeTestRule.setContent {
        AuthScreen(onAuthSuccess = { _, _, _ -> })
    }
    
    composeTestRule
        .onNodeWithText("XBoard")
        .assertIsDisplayed()
    
    composeTestRule
        .onNodeWithText("Залоговый токен")
        .assertIsDisplayed()
}
```

## Сборка и развертывание

### Debug сборка

```bash
./gradlew assembleDebug
```

### Release сборка

```bash
./gradlew assembleRelease
```

### Подписание APK

```kotlin
android {
    signingConfigs {
        create("release") {
            storeFile = file("keystore.jks")
            storePassword = System.getenv("KEYSTORE_PASSWORD")
            keyAlias = System.getenv("KEY_ALIAS")
            keyPassword = System.getenv("KEY_PASSWORD")
        }
    }
    
    buildTypes {
        release {
            signingConfig = signingConfigs.getByName("release")
            isMinifyEnabled = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
}
```

## Конфигурация

### Переменные окружения

```properties
# API Configuration
API_BASE_URL_DEBUG=http://sandbox.api.xdexboard.com
API_BASE_URL_RELEASE=https://api.xdexboard.com

# Build Configuration
VERSION_NAME=1.0.0
VERSION_CODE=1
```

### ProGuard правила

```proguard
# Retrofit
-keepattributes Signature, InnerClasses, EnclosingMethod
-keepattributes RuntimeVisibleAnnotations, RuntimeVisibleParameterAnnotations
-keepclassmembers,allowshrinking,allowobfuscation interface * {
    @retrofit2.http.* <methods>;
}

# Kotlinx Serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.AnnotationsKt
```

## Лицензия

Этот проект лицензирован под MIT License - см. файл [LICENSE](LICENSE) для деталей.

## Поддержка

- **План работы**: [WORK_PLAN.md](WORK_PLAN.md)
- **API документация**: [../README.md](../README.md)
- **Backend репозиторий**: [https://github.com/xrpchange/xboard](https://github.com/xrpchange/xboard)

---
*Последнее обновление: $(date)*
