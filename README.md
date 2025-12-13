# task-hipster
Task A — Bulk Import + Chunked Drag-and-Drop Image Upload | Task B — Laravel Package: User Discounts
# Laravel 12 + Next.js (Hybrid) Application

This project is a **hybrid application** built with **Laravel 12** as the backend and **Next.js** as the frontend. The Next.js frontend lives inside the Laravel **resources** directory and communicates with Laravel APIs using a configured backend URL.

---

## Tech Stack

* **Backend:** Laravel 12 (PHP 8.2+)
* **Frontend:** Next.js (inside Laravel resources folder)
* **Database:** MySQL
* **API Communication:** REST APIs

---

## Project Structure

```
root/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/
├── resources/
│   └── frontend/        # Next.js application
├── routes/
├── tests/
├── .env
└── composer.json
```

---

## Backend Setup (Laravel 12)

### 1. Install PHP dependencies

```bash
composer install
```

### 2. Environment configuration

Copy `.env.example` to `.env` and update database details:

```bash
cp .env.example .env
php artisan key:generate
```

**MySQL Configuration (example):**

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=task_hipster
DB_USERNAME=root
DB_PASSWORD=secret
```

### 3. Run migrations

```bash
php artisan migrate
```

### 4. Run Laravel with HTTPS (required)

The frontend consumes the backend using the following URL:

```
https://task-hipster.test/
```

Make sure Laravel is served over **HTTPS** (for example using Laravel Valet or a trusted local SSL setup).

```bash
php artisan serve
```

---

## Frontend Setup (Next.js)

The Next.js application is located inside the Laravel **resources** folder.

### 1. Navigate to frontend directory

```bash
cd resources/frontend
```

### 2. Install Node dependencies

```bash
npm install
```

### 3. Environment configuration

Create or update `.env.local` in the frontend directory:

```dotenv
NEXT_PUBLIC_API_URL=https://task-hipster.test/api
```

This URL is used by Next.js to communicate with the Laravel backend APIs.

### 4. Run Next.js development server

```bash
npm run dev
```

> ⚠️ **Important:** Laravel must be running before starting the Next.js app, as the frontend depends on backend APIs.

---

## Running the Application

1. Start Laravel (HTTPS enabled)
2. Start Next.js from `resources/frontend`
3. Access the frontend in your browser (as configured by Next.js)

---

## User Discount Package

This project includes a **custom Laravel package** related to **User Discounts**.

### Package Responsibilities

* Manage user-based discounts
* Apply discount rules
* Provide reusable services for discount calculation

### Package Structure (example)

```
packages/
└── pawan/
    └── laravel-user-discounts/
        ├── src/
        │   ├── UserDiscountService.php
        │   ├── Models/
        │   └── Providers/
        └── tests/
            └── UserDiscountTest.php
```

---

## Package Test Cases

Test cases are created to validate the **User Discount package**, including:

* Discount calculation logic
* User eligibility validation
* Edge cases and invalid scenarios

### Running Tests

```bash
php artisan test
```

---

## Notes

* Laravel and Next.js are tightly coupled through API communication
* HTTPS is mandatory for local development
* MySQL is required as the database
* Frontend depends entirely on backend availability

---

## License

This project is open-sourced under the MIT license.

