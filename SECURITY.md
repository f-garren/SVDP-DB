# Security Features

This document outlines the security measures implemented in the SVDP Database Management System.

## Input Validation and Sanitization

### String Input
- All user input is sanitized using `sanitizeString()` function
- HTML tags are stripped by default (except where explicitly allowed)
- Maximum length validation enforced
- Special character validation for names and addresses

### Numeric Input
- Integers validated with `sanitizeInt()` with min/max bounds
- Floats validated with `sanitizeFloat()` with min/max bounds
- Phone numbers validated for format (10-15 digits)
- ZIP codes validated for US format (5 digits or 5+4)
- State codes validated (exactly 2 uppercase letters)

### Date/Time Input
- Date formats validated using `validateDate()`
- DateTime formats validated using `validateDateTime()`
- Invalid dates rejected or defaulted to current date

### File Uploads
- File type validation (only .sql for database restore)
- File size limits (10MB maximum)
- Content scanning for malicious code (PHP, JavaScript)
- Secure temporary file handling

## Cross-Site Scripting (XSS) Protection

### Output Escaping
- All output uses `h()` function which calls `htmlspecialchars()` with ENT_QUOTES
- JavaScript context uses `escapeJS()` for JSON encoding
- No raw user input displayed without escaping

### Content Security
- HTML tags stripped from user input by default
- Only specific fields allow limited HTML (with proper escaping)

## Cross-Site Request Forgery (CSRF) Protection

### Token Generation
- Unique CSRF token generated per session using `generateCSRFToken()`
- Tokens stored in session, not cookies
- 64-character random hexadecimal tokens

### Token Verification
- All POST forms include CSRF token
- Tokens verified using `verifyCSRFToken()` with timing-safe comparison
- Invalid tokens result in error message and request rejection

## SQL Injection Prevention

### Prepared Statements
- All database queries use PDO prepared statements
- No string concatenation in SQL queries
- Parameters bound using placeholders (?)

### Database Configuration
- Database credentials stored in config file (not in code)
- Limited database user permissions (only necessary privileges)
- Connection uses UTF-8 charset to prevent encoding issues

## Authentication and Authorization

### Password Security
- Passwords hashed using bcrypt (cost factor 12)
- Password reset required on first login
- Minimum password length: 8 characters
- Passwords never stored in plain text

### Session Security
- Secure session configuration:
  - HttpOnly cookies (prevents JavaScript access)
  - Secure flag (HTTPS only when available)
  - SameSite=Strict (prevents CSRF)
  - Session regeneration on login (prevents fixation)
- Session timeout: 1 hour (configurable)

### Role-Based Access Control
- Permission-based access control
- Admin accounts have all permissions
- Each action checks required permission
- Unauthorized access redirects with error message

## Rate Limiting

### Login Protection
- Maximum 5 login attempts per 15 minutes
- Rate limit tracked per session
- Exceeding limit shows error message
- Reset time displayed to user

## Error Handling

### Information Disclosure Prevention
- Database errors logged but not displayed to users
- Generic error messages shown to users
- Detailed errors only in server logs
- Stack traces disabled in production

## Security Headers

### HTTP Security Headers
- `X-Content-Type-Options: nosniff` - Prevents MIME type sniffing
- `X-Frame-Options: DENY` - Prevents clickjacking
- `X-XSS-Protection: 1; mode=block` - Enables browser XSS filter
- `Referrer-Policy: strict-origin-when-cross-origin` - Limits referrer information
- `Strict-Transport-Security` - Forces HTTPS (when HTTPS enabled)

## File System Security

### Directory Protection
- `.htaccess` file protects sensitive directories
- Backups directory access restricted
- Config file access blocked via web server

### File Permissions
- Proper file ownership (www-data user)
- Directory permissions: 755
- File permissions: 644
- Executable permissions only where needed

## Data Validation

### Customer Data
- Name: 2-255 characters, no HTML/special chars
- Address: 5-255 characters
- City: 2-100 characters
- State: Exactly 2 uppercase letters
- ZIP: US format (5 or 5+4 digits)
- Phone: 10-15 digits, properly formatted

### Visit Data
- Visit type: Must be one of allowed types
- Dates: Validated format and range
- Amounts: Validated as positive numbers with limits
- Notes: Limited to 5000 characters

### Employee Data
- Username: 3-100 characters, alphanumeric + underscore only
- Permissions: Must be from allowed list
- Password: Minimum 8 characters

## Best Practices

1. **Never trust user input** - All input validated and sanitized
2. **Use prepared statements** - All database queries parameterized
3. **Escape all output** - XSS prevention on all displayed data
4. **CSRF protection** - All state-changing operations protected
5. **Least privilege** - Users only have necessary permissions
6. **Secure defaults** - Security features enabled by default
7. **Error logging** - Security events logged for monitoring
8. **Regular updates** - Keep PHP and dependencies updated

## Security Checklist

- [x] Input validation and sanitization
- [x] XSS protection (output escaping)
- [x] CSRF protection (tokens)
- [x] SQL injection prevention (prepared statements)
- [x] Authentication and authorization
- [x] Password hashing (bcrypt)
- [x] Session security
- [x] Rate limiting
- [x] Error handling (no information disclosure)
- [x] Security headers
- [x] File upload validation
- [x] Secure file permissions

## Reporting Security Issues

If you discover a security vulnerability, please:
1. Do not disclose publicly
2. Report to system administrator immediately
3. Provide detailed information about the issue
4. Allow time for fix before disclosure

