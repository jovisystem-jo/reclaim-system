# Reclaim System Web + Android User App

This project now supports:

- Existing PHP website for public, user, staff, and admin flows
- New JSON mobile API for the native Android user app
- New native Android app project for `student` user accounts only

## Current Website Stack

- Framework: custom procedural PHP application
- Database: MySQL via PDO
- Auth on website: PHP session-based login
- Mail: PHPMailer with Gmail SMTP
- Mobile auth: bearer token authentication backed by the same `users` table

## User Features Identified From The Existing Website

The Android app mirrors the current user-side feature set that already exists on the website:

- Register / Login / Logout
- User dashboard
- Search and view item details
- Submit claim for found items
- View own claims and confirm reclaim
- View own reported items
- Report lost/found items
- View notifications
- View and update user profile

Admin and staff pages remain website-only.

## Mobile API

New mobile API routes live under:

- `api/mobile/auth/`
- `api/mobile/items/`
- `api/mobile/claims/`
- `api/mobile/notifications/`
- `api/mobile/meta/`

Shared mobile API helpers:

- `includes/mobile_api.php`
- `api/mobile/bootstrap.php`

### Auth Model

- App login uses the same `users` table as the website
- Passwords remain hashed with `password_hash`
- Mobile app access is restricted to `role = student`
- `staff` and `admin` accounts are blocked with a clear API error
- Bearer tokens are stored in the new `mobile_api_tokens` table
- Token table is auto-created by the API bootstrap on first use

### Main API Endpoints

#### Auth

- `POST /api/mobile/auth/register.php`
- `POST /api/mobile/auth/login.php`
- `GET /api/mobile/auth/me.php`
- `POST /api/mobile/auth/logout.php`

#### User Data

- `GET /api/mobile/dashboard.php`
- `GET /api/mobile/profile.php`
- `POST /api/mobile/profile.php`

#### Items

- `GET /api/mobile/items/index.php?scope=public`
- `GET /api/mobile/items/index.php?scope=mine`
- `GET /api/mobile/items/show.php?id={itemId}`
- `POST /api/mobile/items/report.php`
- `POST /api/mobile/items/update.php`

#### Claims

- `GET /api/mobile/claims/index.php`
- `POST /api/mobile/claims/submit.php`
- `POST /api/mobile/claims/cancel.php`
- `POST /api/mobile/claims/complete.php`

#### Notifications

- `GET /api/mobile/notifications/index.php`
- `POST /api/mobile/notifications/mark-read.php`
- `POST /api/mobile/notifications/mark-all-read.php`

#### Options / Metadata

- `GET /api/mobile/meta/options.php`

## Sample API Responses

### Login Success

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token": "rma_xxx",
    "token_type": "Bearer",
    "expires_in": 2592000,
    "user": {
      "id": 19,
      "name": "Mobile Test Student",
      "email": "student@example.com",
      "username": "student_user",
      "role": "student"
    }
  }
}
```

### Validation Error

```json
{
  "success": false,
  "message": "Please correct the highlighted fields.",
  "error_code": "validation_failed",
  "errors": {
    "email": "Email must be valid."
  }
}
```

### Unauthorized Token

```json
{
  "success": false,
  "message": "Authentication token is invalid.",
  "error_code": "invalid_token"
}
```

### Staff/Admin Blocked From Mobile App

```json
{
  "success": false,
  "message": "Only student user accounts can sign in to the mobile app.",
  "error_code": "role_not_allowed"
}
```

## Android App Project

The native Android app is in:

- `android-user-app/`

### Android Structure

- `auth/`
- `api/network/`
- `models/`
- `screens/activities/`
- `screens/fragments/`
- `screens/adapters/`
- `storage/session/`

### Android Tech Choices

- Kotlin
- Retrofit
- OkHttp
- EncryptedSharedPreferences
- Activities + Fragments
- RecyclerView
- Material Components

### Configure The API Base URL

Edit:

- `android-user-app/app/build.gradle`

Current default:

```gradle
buildConfigField "String", "API_BASE_URL", "\"http://10.0.2.2/reclaim-system/api/mobile/\""
```

Use:

- `10.0.2.2` for Android emulator talking to XAMPP on the same PC
- Your machine IP for a physical device on the same network
- HTTPS production URL when deploying

Example for a physical device:

```gradle
buildConfigField "String", "API_BASE_URL", "\"http://192.168.1.20/reclaim-system/api/mobile/\""
```

## How Authentication Works

### Website

- Uses existing session login in `login.php`
- Unchanged web routes continue to work normally

### Android App

1. User logs in or registers through mobile API
2. API returns bearer token
3. Token is stored in `EncryptedSharedPreferences`
4. `AuthInterceptor` attaches `Authorization: Bearer <token>` to protected calls
5. Logout clears the local token and revokes the server token

## Backend Setup

1. Place the project inside XAMPP `htdocs`
2. Ensure Apache and MySQL are running
3. Confirm database credentials in `config/database.php`
4. Confirm SMTP settings in `.env` / environment variables:
   - `SMTP_USERNAME`
   - `SMTP_PASSWORD`
   - optional `MAIL_FROM_EMAIL`
   - optional `MAIL_FROM_NAME`
5. Open the site:
   - `http://localhost/reclaim-system/`

The mobile token table is created automatically when a mobile API route is used.

## Android App Setup

1. Open `android-user-app/` in Android Studio
2. Install Android SDK required for `compileSdk 34`
3. Use JDK 17 for Android Gradle Plugin 8.5.x
4. Sync Gradle
5. Update `API_BASE_URL` if needed
6. Run on emulator or device

## Notes On Compatibility

- The website still uses its original PHP pages
- The Android app uses only the new `/api/mobile/...` routes
- The Android app never connects directly to MySQL
- Website session auth and mobile token auth are separate but share the same `users` table

## Important Security Notes

- Mobile API returns JSON only
- Protected endpoints require bearer token auth
- Password hashes are never returned
- User payloads exclude sensitive auth fields
- Only student accounts are allowed into the mobile app
- Session fallback now uses `storage/sessions/` if the default PHP session path is not writable

## Testing Performed

### Live backend/API tests completed

- `php -l` passed on all new mobile API files
- `php -l` passed on updated shared helpers
- Mobile API registration succeeded for a test student account
- Mobile API login succeeded for the same account
- Protected `me` endpoint worked with a valid token
- Protected `dashboard` endpoint worked with a valid token
- Invalid token returned `invalid_token`
- Logout revoked the token successfully
- Reusing the revoked token returned `revoked_token`
- A test `staff` account was blocked from mobile login with `role_not_allowed`

### Website compatibility checks completed

- `login.php` loads normally
- `register.php` loads normally
- Website login still works against the shared `users` table
- A student account created through the mobile API successfully logged into the website and redirected to `user/dashboard.php`
- Website register flow still reached the OTP verification step after form submission

### Not fully completed in this environment

- Full Android APK build was not run here because Gradle is not installed in this environment
- End-to-end emulator/device interaction was not run here
- Website OTP completion was not finished end-to-end because that requires mailbox access to the sent verification code

## Suggested Next Steps

1. Open `android-user-app/` in Android Studio
2. Set the correct API base URL
3. Run the app on emulator
4. Test:
   - student register/login
   - profile update
   - report item
   - claim submission
   - notifications
5. If needed, extend the Android app to support editing reported items with the already-prepared backend endpoint
