# Mascardi Car Yard Management System

A comprehensive PHP-based management system designed for car yards, focusing on logistics, workshop management, and financial tracking.

## Features
- **Dashboard**: Real-time statistics of vehicle status (In Transit, In Workshop, Completed, etc.).
- **Car Inventory**: Comprehensive tracking of chassis numbers, registration, and vehicle details.
- **Logistics (Intake & Transfers)**: Manage vehicle arrivals at Mombasa Port and transfers to Nairobi.
- **Workshop Management**: Assessment tracking, job cards, and mechanic assignments.
- **Inventory & Spare Parts**: Track stock levels, low-stock alerts, and supplier integration.
- **Finance**: Generate Quotations, Invoices, and Local Purchase Orders (LPO).

## Tech Stack
- **Backend**: PHP 8.x
- **Database**: MySQL / MariaDB
- **Frontend**: Bootstrap 5, FontAwesome, Custom CSS
- **Server**: Compatible with Apache/Nginx or PHP built-in server

## Setup Instructions

### 1. Database Configuration
1. Create a database named `mascardi_db`.
2. Import the schema from `database/schema.sql`.
3. Update database credentials in `config/database.php`.

### 2. Application Configuration
Update the `BASE_URL` in `config/app.php` to match your local server environment.

```php
define('BASE_URL', 'http://localhost:8001');
```

### 3. Run Locally
Using PHP built-in server:
```bash
php -S localhost:8001
```

## Project Structure
- `/assets`: CSS, JS, and image files.
- `/config`: Application and database configuration.
- `/database`: SQL schema and migrations.
- `/includes`: Common functions, headers, and footers.
- `/modules`: Feature-specific modules (Cars, Workshop, Invoices, etc.).
