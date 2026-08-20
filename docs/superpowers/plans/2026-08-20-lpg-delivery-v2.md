# LPG Delivery System v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a secure, organized, modular Native PHP + jQuery + Bootstrap 5 LPG Delivery Web Application in `lpg-delivery-system-repo-2/` with a unified database, role-based access control, CSRF protection, transactional concurrency, and role-segregated portals.

**Architecture:** Approach A (Organized Multi-Page PHP with Role Directories). Server-rendered views with Bootstrap 5 components, coupled with jQuery AJAX endpoints for dynamic order transitions, inventory mutations, and user account status updates. 

**Tech Stack:** Native PHP 8+, PDO MySQL, jQuery 3.7.1, Bootstrap 5.3.3 & Icons, PHPMailer.

**Spec:** `docs/superpowers/specs/2026-08-20-lpg-delivery-v2-design.md`

## Global Constraints
- Target directory: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2`
- Database: `lpg_delivery_v2`
- Passwords must be hashed using `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`
- All database queries must use parameterized PDO prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`)
- CSRF tokens must be enforced on all state-changing POST forms and AJAX requests
- HTML output must be escaped using `e()` helper function (`htmlspecialchars` with `ENT_QUOTES | ENT_HTML5`)
- Role authorization must be strictly enforced server-side before rendering any protected view or executing any API action
- File uploads for valid IDs must be strictly MIME-type validated (`finfo`), assigned random hex hashes, and placed in `.htaccess`-protected directories

---

### Task 1: Database Setup & Configuration Layer

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/database/lpg_delivery_v2.sql`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/database/.htaccess`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/config/database.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/config/app.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/config/mail.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/config/.htaccess`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/classes/Database.php`
- Test: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_db.php`

**Interfaces:**
- Produces: `Database::connect(): PDO` returning configured singleton PDO instance.
- Produces: `config/app.php` defining `BASE_URL`, `APP_NAME`, `UPLOAD_PATH`.

- [ ] **Step 1: Write database schema & seed SQL**
Create `database/lpg_delivery_v2.sql` with tables `users`, `products`, `orders`, `password_resets`, appropriate foreign keys, cascade rules, indexes, and seeded bcrypt hashes for test accounts. Add `.htaccess` with `Deny from all`.

- [ ] **Step 2: Write configuration files**
Create `config/database.php` returning DB connection array, `config/app.php` returning application URLs and paths, and `config/mail.php` returning SMTP settings. Add `config/.htaccess` with `Deny from all`.

- [ ] **Step 3: Implement Database singleton class**
Create `classes/Database.php` implementing `connect()` with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`, and `PDO::ATTR_EMULATE_PREPARES => false`.

- [ ] **Step 4: Create automated database connection test script**
Create `tests/test_db.php` verifying connection to `lpg_delivery_v2` and querying tables.
Run: `php /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_db.php`
Expected: Output showing successful connection and seed user counts.

- [ ] **Step 5: Commit changes**
```bash
git add lpg-delivery-system-repo-2/database lpg-delivery-system-repo-2/config lpg-delivery-system-repo-2/classes/Database.php lpg-delivery-system-repo-2/tests
git commit -m "feat(v2): add database schema, config layer, and Database singleton"
```

---

### Task 2: Core Models (User, Product, Order, Mailer)

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/classes/User.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/classes/Product.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/classes/Order.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/classes/Mailer.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/classes/.htaccess`
- Test: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_models.php`

**Interfaces:**
- Produces: `User`: `findByEmail()`, `findById()`, `create()`, `emailExists()`, `updatePassword()`, `updateProfile()`, `updateStatus()`, `getAllByRole()`, `countByRole()`.
- Produces: `Product`: `getActive()`, `getAll()`, `findById()`, `findByIdForUpdate()`, `decrementStock()`, `updateStock()`, `updatePrice()`, `toggleStatus()`.
- Produces: `Order`: `place()`, `getByCustomer()`, `getByRider()`, `getAvailableForRider()`, `getAll()`, `updateStatus()`, `assignRider()`, `getDashboardStats()`.
- Produces: `Mailer`: `sendResetEmail()`.

- [ ] **Step 1: Implement `User.php`**
Write User class methods with parameterized queries and bcrypt handling.

- [ ] **Step 2: Implement `Product.php`**
Write Product class methods including `findByIdForUpdate()` for pessimistic row locking.

- [ ] **Step 3: Implement `Order.php`**
Write Order class methods with database transactions inside `place()`, status transition validation via state machine matrix, and concurrency-safe `assignRider()`.

- [ ] **Step 4: Implement `Mailer.php`**
Write PHPMailer wrapper with fallback mock mode for testing environments. Add `classes/.htaccess` with `Deny from all`.

- [ ] **Step 5: Create and run models test script**
Create `tests/test_models.php` testing user lookup, product active listings, order calculation, and status transition rules.
Run: `php /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_models.php`
Expected: All model assertion tests pass.

- [ ] **Step 6: Commit changes**
```bash
git add lpg-delivery-system-repo-2/classes lpg-delivery-system-repo-2/tests/test_models.php
git commit -m "feat(v2): implement User, Product, Order, and Mailer model classes"
```

---

### Task 3: Security & Middleware Layer

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/includes/auth.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/includes/middleware.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/includes/helpers.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/includes/.htaccess`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/.htaccess`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/uploads/.htaccess`
- Test: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_security.php`

**Interfaces:**
- Produces: `csrf_token(): string`, `verify_csrf(): void`, `check_rate_limit(string, int, int): bool`.
- Produces: `require_role(string ...$roles): void`, `require_login(): void`.
- Produces: `e(string): string`, `redirect(string): void`, `set_flash(string, string): void`, `get_flash(): ?array`.

- [ ] **Step 1: Implement `includes/auth.php`**
Initialize secure session configuration (httponly, samesite=Strict, use_strict_mode), 30-min expiration check, CSRF generator and validator, rate limiting helper.

- [ ] **Step 2: Implement `includes/middleware.php`**
Implement `require_login()` and `require_role()` returning proper HTTP 403 or redirecting unauthenticated sessions.

- [ ] **Step 3: Implement `includes/helpers.php`**
Implement sanitization `e()`, URL helper `url()`, flash messaging system `set_flash()` / `get_flash()`, file upload security validator `validate_id_upload()`.

- [ ] **Step 4: Create root and directory `.htaccess` protection files**
Add security headers (`X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`), disable directory listing (`Options -Indexes`), and block direct access to uploads.

- [ ] **Step 5: Create and run security test script**
Create `tests/test_security.php` testing CSRF validation, XSS escaping, rate limiting, and role authorization logic.
Run: `php /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_security.php`
Expected: All security assertion tests pass.

- [ ] **Step 6: Commit changes**
```bash
git add lpg-delivery-system-repo-2/includes lpg-delivery-system-repo-2/.htaccess lpg-delivery-system-repo-2/uploads lpg-delivery-system-repo-2/tests/test_security.php
git commit -m "feat(v2): implement session security, CSRF, middleware, and helpers"
```

---

### Task 4: Template System & Shared Layouts

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/header.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/footer.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/sidebar.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/components/alert.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/components/modal.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/components/order-card.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/.htaccess`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/assets/css/app.css`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/assets/js/app.js`

**Interfaces:**
- Produces: Shared HTML shell with Bootstrap 5.3 CDN, Bootstrap Icons, jQuery 3.7.1, global AJAX CSRF setup, responsive mobile sidebar toggle, and toast notifications.

- [ ] **Step 1: Implement `templates/header.php` and `templates/footer.php`**
Create the base responsive layout including meta tags, CSRF header, Bootstrap CDN links, top navigation bar with user profile dropdown/avatar, and script inclusions.

- [ ] **Step 2: Implement `templates/sidebar.php`**
Build dynamic role-aware navigation rendering menu items for customer, admin, or rider based on `$_SESSION['user_role']`.

- [ ] **Step 3: Implement components (`alert.php`, `modal.php`, `order-card.php`)**
Create reusable partials for Bootstrap toast alerts and order status cards.

- [ ] **Step 4: Implement `assets/css/app.css` and `assets/js/app.js`**
Write clean CSS tokens and global jQuery behaviors: setup `$.ajaxSetup` with `X-CSRF-Token`, sidebar collapse toggling, and global toast helper `showToast(msg, type)`.

- [ ] **Step 5: Verify template syntax**
Run: `php -l /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/templates/header.php`
Expected: No syntax errors detected.

- [ ] **Step 6: Commit changes**
```bash
git add lpg-delivery-system-repo-2/templates lpg-delivery-system-repo-2/assets
git commit -m "feat(v2): build Bootstrap 5 template layouts, sidebar, and app JS"
```

---

### Task 5: Authentication Pages (Login, Register, Forgot Password, Logout)

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/index.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/register.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/forgot-password.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/logout.php`

**Interfaces:**
- Consumes: `User`, `includes/auth.php`, `includes/middleware.php`, `includes/helpers.php`.
- Produces: Complete public authentication workflows with role-based routing upon login.

- [ ] **Step 1: Implement `index.php` (Login)**
Build login form with rate limiting (5 attempts/15 mins), CSRF validation, email/password verification with `password_verify()`, session regeneration, and role redirection to `pages/{role}/dashboard.php` or `shop.php`.

- [ ] **Step 2: Implement `register.php` (Customer Registration)**
Build registration form with customer role enforcement, live jQuery password checklist, server-side complexity regex, MIME-type validated ID file upload handling, and flash message redirection to login.

- [ ] **Step 3: Implement `forgot-password.php` (Password Recovery)**
Build multi-step token-based reset flow stored in `password_resets` table with expiry validation.

- [ ] **Step 4: Implement `logout.php`**
Centralized session termination, cookie clearing, and redirect to `index.php?logged_out=1`.

- [ ] **Step 5: Test authentication scripts linting and flow**
Run: `php -l /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/index.php && php -l /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/register.php && php -l /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/forgot-password.php`
Expected: No syntax errors detected.

- [ ] **Step 6: Commit changes**
```bash
git add lpg-delivery-system-repo-2/index.php lpg-delivery-system-repo-2/register.php lpg-delivery-system-repo-2/forgot-password.php lpg-delivery-system-repo-2/logout.php
git commit -m "feat(v2): implement secure login, registration, password recovery, and logout"
```

---

### Task 6: Customer Portal

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/customer/dashboard.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/customer/shop.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/customer/orders.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/customer/profile.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/assets/js/customer.js`

**Interfaces:**
- Consumes: `require_role('customer')`, `Product`, `Order`, `User`.
- Produces: Customer product catalog, order placement with quantity controls, order history tracking, and profile management.

- [ ] **Step 1: Implement `pages/customer/dashboard.php`**
Customer overview showing active orders, delivery status badges, and quick reorder shortcuts.

- [ ] **Step 2: Implement `pages/customer/shop.php` & `assets/js/customer.js`**
Product catalog cards with stock indicators, quantity counter (+/-), payment mode selection (COD, GCash), transactional order placement POST handler with stock verification and price snapshotting.

- [ ] **Step 3: Implement `pages/customer/orders.php`**
Order history view with status progression tracking, unit price, quantity, total amount, assigned rider information, and timestamps.

- [ ] **Step 4: Implement `pages/customer/profile.php`**
Profile viewing and update form with server-side phone/address validation and CSRF token protection.

- [ ] **Step 5: Lint customer pages**
Run: `php -l /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/customer/shop.php`
Expected: No syntax errors detected.

- [ ] **Step 6: Commit changes**
```bash
git add lpg-delivery-system-repo-2/pages/customer lpg-delivery-system-repo-2/assets/js/customer.js
git commit -m "feat(v2): implement customer shop, order tracking, and profile portals"
```

---

### Task 7: Admin Portal

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/admin/dashboard.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/admin/orders.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/admin/inventory.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/admin/users.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/assets/js/admin.js`

**Interfaces:**
- Consumes: `require_role('admin')`, `Order`, `Product`, `User`.
- Produces: Operational metrics, order assignment, inventory stock/price management, and user account status controls.

- [ ] **Step 1: Implement `pages/admin/dashboard.php`**
Dashboard with 4 stat cards (Total Orders, Pending, In Transit, Delivered, Total Revenue) and a recent orders table.

- [ ] **Step 2: Implement `pages/admin/orders.php` & `assets/js/admin.js`**
All-orders table with filtering, rider assignment dropdown populated with active riders, status modification triggers, and cancellation handler.

- [ ] **Step 3: Implement `pages/admin/inventory.php`**
Inventory management table with inline stock editing modal, price adjustments, and active/inactive status toggle.

- [ ] **Step 4: Implement `pages/admin/users.php`**
User table listing customer/rider/admin accounts, valid ID view link (proxy secured), and account activation/suspension toggles.

- [ ] **Step 5: Lint admin pages**
Run: `php -l /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/admin/dashboard.php`
Expected: No syntax errors detected.

- [ ] **Step 6: Commit changes**
```bash
git add lpg-delivery-system-repo-2/pages/admin lpg-delivery-system-repo-2/assets/js/admin.js
git commit -m "feat(v2): implement admin dashboard, order dispatch, inventory, and user management"
```

---

### Task 8: Rider Portal

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/rider/deliveries.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/rider/available.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/rider/profile.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/assets/js/rider.js`

**Interfaces:**
- Consumes: `require_role('rider')`, `Order`, `User`.
- Produces: Delivery management, order claiming, status updates (`picked_up` -> `out_for_delivery` -> `delivered`), and rider profile.

- [ ] **Step 1: Implement `pages/rider/deliveries.php` & `assets/js/rider.js`**
Assigned delivery cards with customer address, contact phone, product details, payment info, and sequential status progression buttons (`Pick Up`, `Out for Delivery`, `Mark Delivered`).

- [ ] **Step 2: Implement `pages/rider/available.php`**
List of unassigned ready orders with a single-click "Claim Order" action using concurrency-safe claiming endpoint.

- [ ] **Step 3: Implement `pages/rider/profile.php`**
Rider profile view and contact information editor.

- [ ] **Step 4: Lint rider pages**
Run: `php -l /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/pages/rider/deliveries.php`
Expected: No syntax errors detected.

- [ ] **Step 5: Commit changes**
```bash
git add lpg-delivery-system-repo-2/pages/rider lpg-delivery-system-repo-2/assets/js/rider.js
git commit -m "feat(v2): implement rider deliveries, available order claiming, and profile"
```

---

### Task 9: AJAX API Layer

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/api/orders.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/api/products.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/api/users.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/api/.htaccess`
- Test: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_api.php`

**Interfaces:**
- Produces: JSON API endpoints with role verification and CSRF token validation.

- [ ] **Step 1: Implement `api/orders.php`**
Handle `update_status`, `assign_rider`, `claim`, and `cancel` actions with JSON responses.

- [ ] **Step 2: Implement `api/products.php`**
Handle `update_stock`, `update_price`, and `toggle_status` actions for admin role.

- [ ] **Step 3: Implement `api/users.php`**
Handle `update_status` action for admin role.

- [ ] **Step 4: Create API integration test script**
Create `tests/test_api.php` simulating API requests with invalid CSRF, wrong role, and valid payload execution.
Run: `php /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/test_api.php`
Expected: All API authorization and action tests pass.

- [ ] **Step 5: Commit changes**
```bash
git add lpg-delivery-system-repo-2/api lpg-delivery-system-repo-2/tests/test_api.php
git commit -m "feat(v2): build secure AJAX API layer for orders, products, and users"
```

---

### Task 10: End-to-End Integration Verification & Testing

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/run_all_tests.php`
- Create: `/Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/README.md`

- [ ] **Step 1: Write master test runner `tests/run_all_tests.php`**
Create a comprehensive test runner verifying:
  - Database schema & constraints
  - Model CRUD & pessimistic concurrency locking
  - Order state machine transitions
  - CSRF token lifecycle & rejection
  - Bcrypt password validation
  - Role-based access control filters
  - Rate limiting logic
  - Input validation & XSS sanitization

- [ ] **Step 2: Execute all tests**
Run: `php /Applications/XAMPP/xamppfiles/htdocs/lpg-delivery-system-repo-2/tests/run_all_tests.php`
Expected: 100% test suite pass with 0 failures.

- [ ] **Step 3: Write comprehensive `README.md`**
Document v2 architecture, setup instructions in XAMPP, database import instructions, security highlights, default seed credentials, and directory map.

- [ ] **Step 4: Final commit for v2 project**
```bash
git add lpg-delivery-system-repo-2
git commit -m "chore(v2): complete LPG Delivery System v2 with full test suite and documentation"
```
