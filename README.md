# WHMCS SMS Login & Registration

A WHMCS addon that enables passwordless authentication and user registration using mobile phone numbers and SMS verification codes (OTP).

The module integrates with **[Elanak.ir](https://elanak.ir)** SMS service to deliver verification codes to users.

## Features

- **SMS Login:** Authenticate existing clients using their mobile phone number and OTP.
- **SMS Registration:** Register new clients using mobile number verification.
- **OTP Verification:** Secure one-time password authentication.
- **Configurable OTP Length:** Customize verification code length.
- **Resend OTP:** Configurable resend interval and resend restrictions.
- **Login & Registration Modes:** Enable login, registration, or both.
- **Mobile Number Validation:** Uses the configured WHMCS client custom field.
- **AJAX Communication:** Send and verify OTP codes without page reloads.
- **Responsive Interface:** Clean interface integrated into WHMCS client login and registration pages.
- **Security:** WHMCS token validation and server-side verification.
- **Configurable Settings:** Manage module behavior through the WHMCS admin area.
- **Automatic Login:** Successfully verified users are authenticated and redirected automatically.

## SMS Gateway

This module is integrated with **Elanak.ir** for sending SMS verification messages.

Website: https://elanak.ir

The SMS gateway is used for:
- Sending login verification codes.
- Sending registration verification codes.
- Resending OTP codes.

An active Elanak.ir account and sufficient SMS credit are required.

## Requirements

- WHMCS 8.x
- PHP version compatible with your WHMCS installation
- MySQL / MariaDB
- Active Elanak.ir SMS account
- A configured WHMCS client custom field for mobile numbers

## Installation

### 1. Upload the Module

Upload the module directory to:

```text
/modules/addons/sms_login_registration/
```

### 2. Activate the Addon

1. Log in to the WHMCS Admin Area.
2. Navigate to **System Settings → Addon Modules**.
3. Locate **SMS Login & Registration**.
4. Activate the module.
5. Configure the required access permissions.

### 3. Configure SMS Settings

Open the module configuration page and configure:

- Elanak.ir API credentials.
- SMS sending settings.
- OTP length.
- OTP resend interval.
- Login and registration mode.
- Mobile number custom field ID.

### 4. Configure Mobile Custom Field

Create or select a WHMCS client custom field for mobile phone numbers.

Set its field ID in the module configuration.

The module uses this field to identify existing clients during SMS login.

### 5. Enable the Module

Enable SMS login and registration from the module settings.

The SMS login button will appear on the WHMCS client login page.

## Configuration Options

| Setting | Description |
|---|---|
| Enable | Enable or disable SMS authentication |
| Mode | Login, Registration, or Both |
| OTP Length | Number of verification code digits |
| Resend Interval | Minimum interval between OTP requests |
| Mobile Field ID | WHMCS custom field used for mobile numbers |
| SMS Gateway | Elanak.ir SMS service configuration |

## How It Works

### SMS Login

1. The user opens the WHMCS login page.
2. Selects **Login with Mobile Number**.
3. Enters their mobile phone number.
4. Receives an OTP through Elanak.ir.
5. Enters the verification code.
6. The module verifies the code and authenticates the client.

### SMS Registration

1. The user opens the registration page.
2. Selects SMS registration.
3. Enters the required registration information.
4. Receives an OTP through Elanak.ir.
5. Verifies the code.
6. The client account is created and authenticated.

## Security Considerations

- OTP verification is performed server-side.
- WHMCS security tokens are included in AJAX requests.
- OTP resend intervals help limit repeated requests.
- Authentication is handled through WHMCS mechanisms.
- Keep API credentials private.
- Always use HTTPS on production installations.

## Compatibility

Designed for WHMCS 8.x installations.

Compatibility with other WHMCS versions should be tested before production deployment.

## License

Specify your preferred license before distributing this project.

## Credits

Developed for WHMCS using PHP, JavaScript, AJAX, and Elanak.ir SMS Gateway integration.

**SMS Login & Registration for WHMCS**
