# LPG Delivery System v2 — Design Specification

> **Date:** 2026-08-20
> **Status:** Approved
> **Location:** `lpg-delivery-system-repo-2/` (new directory, sibling to current repo)
> **Stack:** Native PHP 8+ · jQuery 3.7 · Bootstrap 5.3 · MySQL 8 · PHPMailer

---

## 1. Overview

### Problem

The current repository contains two separate, parallel implementations of an LPG delivery system:

1. **SPA + JSON API** (`index.html` + `script.js` + `api/`) — Uses `sia_project_db`. Client-side auth, plaintext passwords, full DB dump/replace on every save. Critical security vulnerabilities: unauthenticated data exposure, no CSRF, no input validation.

2. **Server-Rendered PHP** (`Code/`) — Uses `lpg_delivery`. Better security (bcrypt, sessions, `FOR UPDATE` locking) but incomplete features: admin panel is read-only, no tab switching JS, no CSRF, hardcoded SMTP credentials.

### Solution

Create **v2** in a new directory (`lpg-delivery-system-repo-2/`) that:
- Consolidates both versions into one clean, organized codebase
- Uses `Code/` as the architectural foundation (server-rendered, session auth, bcrypt, PDO)
- Ports missing features from the SPA (admin order management, inventory editing, rider assignment, interactive UI)
- Adds comprehensive security (CSRF, rate limiting, input validation, XSS prevention, file upload hardening)
- Uses Bootstrap 5 for modern, responsive UI
- Uses jQuery for interactive elements (AJAX updates, dynamic forms)
- Combines both databases into a single enhanced schema (`lpg_delivery_v2`)
- Enforces role-based access on every page and endpoint

### Architecture Decision

**Approach A: Organized Multi-Page PHP with Role Directories** was selected over:
- Front Controller + Router (over-engineered for XAMPP)
- API-First SPA (repeats the security mistakes of v1)

---

## 2. Database Schema

Single database: **`lpg_delivery_v2`**

### Tables

#### `users`
| Column | Type | Constraints |
|---|---|---|
| id | INT AUTO_INCREMENT | PRIMARY KEY |
| full_name | VARCHAR(255) | NOT NULL |
| email | VARCHAR(255) | NOT NULL, UNIQUE |
| password | VARCHAR(255) | NOT NULL (bcrypt) |
| role | ENUM('customer','admin','rider') | NOT NULL, DEFAULT 'customer' |
| phone | VARCHAR(20) | NOT NULL |
| address | TEXT | NOT NULL |
| valid_id_path | VARCHAR(500) | DEFAULT NULL |
| status | ENUM('active','inactive','suspended') | NOT NULL, DEFAULT 'active' |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |

Indexes: `idx_role(role)`, `idx_status(status)`

#### `products`
| Column | Type | Constraints |
|---|---|---|
| id | INT AUTO_INCREMENT | PRIMARY KEY |
| name | VARCHAR(255) | NOT NULL |
| brand | VARCHAR(255) | NOT NULL |
| weight | VARCHAR(50) | NOT NULL |
| price | DECIMAL(10,2) | NOT NULL |
| stock | INT UNSIGNED | NOT NULL, DEFAULT 0 |
| image_url | VARCHAR(500) | DEFAULT NULL |
| status | ENUM('active','inactive') | NOT NULL, DEFAULT 'active' |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |

Indexes: `idx_brand(brand)`, `idx_status(status)`

#### `orders`
| Column | Type | Constraints |
|---|---|---|
| id | INT AUTO_INCREMENT | PRIMARY KEY |
| customer_id | INT | NOT NULL, FK → users(id) ON DELETE CASCADE |
| product_id | INT | NOT NULL, FK → products(id) ON DELETE RESTRICT |
| rider_id | INT | DEFAULT NULL, FK → users(id) ON DELETE SET NULL |
| quantity | INT UNSIGNED | NOT NULL |
| unit_price | DECIMAL(10,2) | NOT NULL (snapshot at order time) |
| total_amount | DECIMAL(10,2) | NOT NULL |
| payment_method | ENUM('cod','gcash') | NOT NULL, DEFAULT 'cod' |
| status | ENUM('pending','approved','ready_for_delivery','picked_up','out_for_delivery','delivered','cancelled') | NOT NULL, DEFAULT 'pending' |
| delivery_address | TEXT | NOT NULL |
| contact_phone | VARCHAR(20) | NOT NULL |
| notes | TEXT | DEFAULT NULL |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |
| delivered_at | TIMESTAMP | NULL DEFAULT NULL |

Indexes: `idx_customer(customer_id)`, `idx_rider(rider_id)`, `idx_status(status)`, `idx_created(created_at)`

#### `password_resets`
| Column | Type | Constraints |
|---|---|---|
| id | INT AUTO_INCREMENT | PRIMARY KEY |
| user_id | INT | NOT NULL, FK → users(id) ON DELETE CASCADE |
| token | VARCHAR(255) | NOT NULL (hashed) |
| expires_at | TIMESTAMP | NOT NULL |
| used | TINYINT(1) | NOT NULL, DEFAULT 0 |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |

Indexes: `idx_token(token)`, `idx_expires(user_id, expires_at)`

### Key Design Decisions

- **Price snapshot**: `unit_price` in orders captures the price at order time — future price changes don't alter historical orders
- **Normalized**: Customer/rider names are NOT duplicated in orders — JOINs fetch them from `users`
- **Foreign keys**: Referential integrity enforced at DB level with appropriate cascade rules
- **ENUM statuses**: Invalid values rejected at DB level
- **AUTO_INCREMENT**: Eliminates all manual ID generation race conditions from v1

---

## 3. Security Architecture

### 3.1 Authentication & Session Hardening

- `session_start()` with `cookie_httponly`, `cookie_samesite=Strict`, `use_strict_mode`
- `session_regenerate_id(true)` on login (prevents session fixation)
- 30-minute server-side timeout via `$_SESSION['last_activity']` check
- Centralized in `includes/auth.php`

### 3.2 CSRF Protection

- Token generated via `bin2hex(random_bytes(32))`, stored in session
- Hidden `<input>` in every HTML form
- `X-CSRF-Token` header for all jQuery AJAX requests (via `$.ajaxSetup`)
- Validated with `hash_equals()` (timing-safe)

### 3.3 Role-Based Access Control

- `require_role()` function accepts one or more allowed roles
- Called at the top of every protected page and API endpoint
- Redirects unauthenticated users to login; returns 403 for wrong role

### 3.4 Input Validation

- Server-side validation on ALL inputs using `filter_input()` and regex
- Phone: `/^09\d{9}$/`
- Email: `FILTER_VALIDATE_EMAIL`
- Integers: `FILTER_VALIDATE_INT` with `min_range`/`max_range`
- Client-side validation mirrors server-side (UX only — server is authority)

### 3.5 Output Escaping (XSS Prevention)

- Helper function `e()` wraps `htmlspecialchars(ENT_QUOTES | ENT_HTML5, 'UTF-8')`
- Used on ALL dynamic output in templates
- jQuery uses `.text()` instead of `.html()` for user-generated content

### 3.6 SQL Injection Prevention

- 100% PDO prepared statements — no string concatenation in queries
- `PDO::ATTR_EMULATE_PREPARES => false` for real server-side prepared statements

### 3.7 Password Security

- Bcrypt with cost factor 12: `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`
- Timing-safe verification: `password_verify()`
- Strength rules enforced server-side: 8+ chars, uppercase, lowercase, digit, special char

### 3.8 Rate Limiting

- Session-based: 5 login attempts per 15-minute window
- Applied to login, registration, and password reset endpoints

### 3.9 File Upload Security

- MIME-type validated server-side via `finfo(FILEINFO_MIME_TYPE)`
- Allowed: `image/jpeg`, `image/png`, `image/webp`, `application/pdf`
- Files renamed to random hash (`bin2hex(random_bytes(16))`)
- Stored in `uploads/` protected by `.htaccess` (`Deny from all`)

### 3.10 Security Headers

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Options -Indexes` (no directory listing)

---

## 4. Directory Structure

```
lpg-delivery-system-repo-2/
├── .htaccess                          # Root security headers + directory protection
├── config/
│   ├── .htaccess                      # Deny from all
│   ├── database.php                   # PDO connection config
│   ├── app.php                        # BASE_URL, APP_NAME, paths
│   └── mail.php                       # SMTP settings
├── classes/
│   ├── .htaccess                      # Deny from all
│   ├── Database.php                   # Singleton PDO wrapper
│   ├── User.php                       # User CRUD + auth
│   ├── Product.php                    # Product CRUD + stock (FOR UPDATE)
│   ├── Order.php                      # Order CRUD + state machine
│   └── Mailer.php                     # PHPMailer wrapper
├── includes/
│   ├── .htaccess                      # Deny from all
│   ├── auth.php                       # Session, CSRF, rate limiting
│   ├── middleware.php                 # require_role(), require_login()
│   └── helpers.php                    # e(), redirect(), flash(), validation
├── templates/
│   ├── .htaccess                      # Deny from all
│   ├── header.php                     # HTML head, Bootstrap CSS, topbar, sidebar
│   ├── footer.php                     # jQuery, Bootstrap JS, page-specific JS
│   ├── sidebar.php                    # Role-aware navigation
│   └── components/
│       ├── alert.php                  # Flash message display
│       ├── modal.php                  # Reusable Bootstrap modal
│       └── order-card.php             # Order card partial
├── pages/
│   ├── customer/
│   │   ├── dashboard.php
│   │   ├── shop.php
│   │   ├── orders.php
│   │   └── profile.php
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── orders.php
│   │   ├── inventory.php
│   │   └── users.php
│   └── rider/
│       ├── deliveries.php
│       ├── available.php
│       └── profile.php
├── api/
│   ├── orders.php                     # AJAX: status, assign, claim
│   ├── products.php                   # AJAX: stock, price, toggle
│   └── users.php                      # AJAX: status toggle
├── assets/
│   ├── css/app.css                    # Custom styles
│   └── js/
│       ├── app.js                     # Global: CSRF, sidebar, toasts
│       ├── customer.js                # Shop, quantity, payment
│       ├── admin.js                   # Order mgmt, inventory edits
│       └── rider.js                   # Claim, status updates
├── uploads/
│   ├── .htaccess                      # Deny from all
│   └── ids/                           # Valid ID files
├── database/
│   ├── .htaccess                      # Deny from all
│   └── lpg_delivery_v2.sql           # Schema + seed
├── index.php                          # Login
├── register.php                       # Registration
├── forgot-password.php                # Password reset
└── logout.php                         # Session destroy
```

### Naming Conventions

| Category | Convention | Example |
|---|---|---|
| PHP files | kebab-case | `forgot-password.php` |
| PHP classes | PascalCase | `User.php` |
| DB columns | snake_case | `full_name` |
| CSS | Bootstrap + `app-` prefix | `app-stat-card` |
| JS files | lowercase | `admin.js` |
| Directories | lowercase | `pages/admin/` |

---

## 5. Template System

### Layout Pattern

Every authenticated page follows:
1. `require includes/auth.php` (session + CSRF)
2. `require includes/middleware.php` → `require_role()`
3. Load model classes, fetch data
4. Set `$page_title`, `$current_page`, `$page_js`
5. `require templates/header.php` (Bootstrap CSS, topbar, sidebar)
6. Page HTML content (Bootstrap components)
7. `require templates/footer.php` (jQuery, Bootstrap JS, page JS)

### External Dependencies (CDN)

- Bootstrap 5.3.3 CSS + JS Bundle
- Bootstrap Icons 1.11.3
- jQuery 3.7.1
- PHPMailer (Composer or manual include)

### Sidebar Navigation

Role-aware: each role sees only their menu items. Active page highlighted. Collapsible on mobile via jQuery toggle.

---

## 6. Core Classes

### Database (Singleton)
- Single PDO instance with exception mode, associative fetch, real prepared statements

### User
- `findByEmail()`, `findById()`, `create()`, `emailExists()`
- `updatePassword()`, `updateProfile()`, `updateStatus()`
- `getAllByRole()`, `countByRole()`

### Product
- `getActive()`, `getAll()`, `findById()`, `findByIdForUpdate()`
- `decrementStock()`, `updateStock()`, `updatePrice()`, `toggleStatus()`

### Order
- `place()` — transactional with pessimistic locking
- `getByCustomer()`, `getByRider()`, `getAvailableForRider()`, `getAll()`
- `updateStatus()` — enforces state machine transitions
- `assignRider()` — concurrency-safe (`rider_id IS NULL` check)
- `getDashboardStats()`

### Mailer
- PHPMailer wrapper for password reset emails
- Config from `config/mail.php`

---

## 7. AJAX API Layer

Three endpoints in `api/`:

### `api/orders.php`
- `update_status` — Admin/Rider: change order status (state machine validated)
- `assign_rider` — Admin: assign rider to order
- `claim` — Rider: self-assign to available order

### `api/products.php`
- `update_stock` — Admin: set stock level
- `update_price` — Admin: set price
- `toggle_status` — Admin: activate/deactivate product

### `api/users.php`
- `update_status` — Admin: activate/suspend user

All endpoints: auth-gated, CSRF-validated, JSON responses, input-validated.

---

## 8. Order Status State Machine

Valid transitions enforced server-side:

```
pending → approved | cancelled
approved → ready_for_delivery | cancelled
ready_for_delivery → picked_up
picked_up → out_for_delivery
out_for_delivery → delivered
```

- `pending → approved`: Admin approves
- `approved → ready_for_delivery`: Admin marks ready
- `ready_for_delivery → picked_up`: Rider claims
- `picked_up → out_for_delivery`: Rider starts delivery
- `out_for_delivery → delivered`: Rider confirms (sets `delivered_at`)
- Any cancellable state → `cancelled`: Admin cancels

---

## 9. Feature Mapping (v1 → v2)

### Included
- ✅ Login/register/forgot-password (from Code/, enhanced)
- ✅ Customer: browse, order, track, profile (from both, merged)
- ✅ Admin: dashboard, stats, orders, inventory, users (SPA features + Code/ security)
- ✅ Rider: deliveries, available, claim, profile (from both, merged)
- ✅ COD + GCash payment methods (from SPA)
- ✅ Password strength checklist (from both)
- ✅ Responsive design (Bootstrap 5 replaces custom CSS)

### Excluded
- 🗑️ Leaflet map tracking (simulated data — no real GPS)
- 🗑️ Client-side database / localStorage auth
- 🗑️ Role selection screen (role from DB, not user choice)
- 🗑️ Raw socket SMTP (replaced by PHPMailer)
- 🗑️ `api_check.php` debug tool (security vulnerability)
- 🗑️ Full DB truncate-and-replace data persistence

---

## 10. Seed Data

Three test accounts (all with bcrypt-hashed passwords):

| Role | Email | Password |
|---|---|---|
| Admin | admin@lpg.com | Admin@2026! |
| Rider | rider@lpg.com | Rider@2026! |
| Customer | customer@lpg.com | Customer@2026 |

Five LPG products: Solane 11kg, Gasul 11kg, Total 11kg, Solane 22kg, Gasul 50kg

Sample orders for testing the status flow.
