# Order Processing & Dispatch Management System

A secure, role-based PHP web application for managing orders (truck counts), recording dispatches, and generating analytics reports. Built with PHP 8.1+, MySQL, and Bootstrap for a production-ready LAMP/LEMP stack deployment.

## Features

- **Role-based Access Control**: Entry, View, and Admin roles with appropriate permissions
- **Order Management**: Create, edit, and track orders with auto-generated order numbers
- **Dispatch Tracking**: Record dispatches against orders with validation constraints
- **Dashboard Analytics**: Product-wise totals and 6-month trend analysis
- **Reporting**: Party-wise reports with PDF and Excel export capabilities
- **Security**: bcrypt password hashing, CSRF protection, session management, rate limiting
- **Audit Trail**: Complete audit logging for all data changes

## Tech Stack

- **Backend**: PHP 8.1+ with custom MVC architecture
- **Database**: MySQL 8.0+ / MariaDB 10.3+
- **Frontend**: Server-rendered HTML with Bootstrap 5, vanilla JavaScript
- **Libraries**: PhpSpreadsheet (Excel), TCPDF (PDF), PHPUnit (Testing)

## Quick Start

### Prerequisites

- PHP 8.1 or higher
- MySQL 8.0+ or MariaDB 10.3+
- Composer
- Web server (Apache/Nginx)

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd order-processing-system
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp env.example .env
   # Edit .env with your database credentials
   ```

4. **Create database**
   ```sql
   CREATE DATABASE order_processing;
   ```

5. **Run migrations and seed data**
   ```bash
   php scripts/migrate.php
   php scripts/seed.php
   ```

6. **Configure web server**
   
   **Apache (.htaccess)**
   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_FILENAME} !-f
   RewriteCond %{REQUEST_FILENAME} !-d
   RewriteRule ^(.*)$ public/index.php [QSA,L]
   ```
   
   **Nginx**
   ```nginx
   location / {
       try_files $uri $uri/ /public/index.php?$query_string;
   }
   ```

7. **Access the application**
   - URL: `http://your-domain.com`
   - Default admin: `admin@example.com` / `Passw0rd!`

## Default User Accounts

| Email | Password | Role | Permissions |
|-------|----------|------|-------------|
| admin@example.com | Passw0rd! | Admin | Full access, user management |
| entry@example.com | Passw0rd! | Entry | Create orders, dispatches |
| view@example.com | Passw0rd! | View | View data, generate reports |

**⚠️ Change default passwords immediately in production!**

## API Documentation

### Authentication

#### POST /api/login
```bash
curl -X POST http://your-domain.com/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "Passw0rd!"
  }'
```

#### POST /api/logout
```bash
curl -X POST http://your-domain.com/api/logout \
  -H "X-CSRF-Token: your-csrf-token"
```

### Orders

#### GET /api/orders
```bash
# List orders with filters
curl "http://your-domain.com/api/orders?start_date=2024-01-01&end_date=2024-12-31&status=pending"
```

#### POST /api/orders
```bash
# Create new order
curl -X POST http://your-domain.com/api/orders \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: your-csrf-token" \
  -d '{
    "order_date": "2024-10-01",
    "product_id": 1,
    "order_qty_trucks": 50,
    "party_id": 1
  }'
```

#### GET /api/orders/{id}
```bash
# Get order details
curl "http://your-domain.com/api/orders/1"
```

#### PUT /api/orders/{id}
```bash
# Update order
curl -X PUT http://your-domain.com/api/orders/1 \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: your-csrf-token" \
  -d '{
    "order_qty_trucks": 75
  }'
```

### Dispatches

#### POST /api/orders/{id}/dispatches
```bash
# Create dispatch for order
curl -X POST http://your-domain.com/api/orders/1/dispatches \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: your-csrf-token" \
  -d '{
    "dispatch_date": "2024-10-02",
    "dispatch_qty_trucks": 25,
    "vehicle_no": "TRK-001",
    "remarks": "First batch delivery"
  }'
```

#### GET /api/dispatches
```bash
# List dispatches
curl "http://your-domain.com/api/dispatches?order_id=1"
```

### Dashboard

#### GET /api/dashboard
```bash
# Get dashboard analytics
curl "http://your-domain.com/api/dashboard?start_date=2024-01-01&end_date=2024-12-31"
```

### Reports

#### GET /api/reports/partywise
```bash
# Get party-wise report data
curl "http://your-domain.com/api/reports/partywise?start_date=2024-01-01&end_date=2024-12-31"
```

#### GET /api/reports/partywise/export
```bash
# Export report as PDF
curl "http://your-domain.com/api/reports/partywise/export?format=pdf&start_date=2024-01-01&end_date=2024-12-31" \
  --output report.pdf

# Export report as Excel
curl "http://your-domain.com/api/reports/partywise/export?format=xlsx&start_date=2024-01-01&end_date=2024-12-31" \
  --output report.xlsx
```

### User Management (Admin Only)

#### GET /api/users
```bash
# List all users
curl "http://your-domain.com/api/users"
```

#### POST /api/users
```bash
# Create new user
curl -X POST http://your-domain.com/api/users \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: your-csrf-token" \
  -d '{
    "email": "newuser@example.com",
    "password": "SecurePassword123!",
    "name": "New User",
    "role_id": 2,
    "is_active": true
  }'
```

## Database Schema

### Core Tables

- **roles**: User roles (entry, view, admin)
- **users**: System users with authentication
- **parties**: Customer/client organizations
- **products**: Product catalog
- **orders**: Order records with truck quantities
- **dispatches**: Dispatch records against orders
- **sessions**: Session management
- **audit_logs**: Change tracking

### Key Relationships

```sql
orders -> parties (party_id)
orders -> products (product_id)
orders -> users (created_by)
dispatches -> orders (order_id)
dispatches -> users (dispatched_by)
```

## Business Rules

### Order Management
- Order numbers auto-generated: `ORD-YYYYMM####`
- Orders can only be edited if not fully dispatched
- Order quantity cannot be reduced below total dispatched

### Dispatch Management
- Total dispatched cannot exceed order quantity
- Multiple dispatches allowed per order
- Order status updates automatically:
  - `pending`: No dispatches
  - `partial`: Some dispatches, not complete
  - `completed`: Fully dispatched

### Security Rules
- Passwords must be 8+ characters
- Account lockout after 5 failed login attempts (15 minutes)
- CSRF protection on all state-changing operations
- Role-based access control enforced

## Testing

### Run Tests
```bash
# Run all tests
php scripts/test.php

# Or use PHPUnit directly
./vendor/bin/phpunit
```

### Test Coverage
- Order creation and validation
- Dispatch constraints and validation
- Authentication and authorization
- Business rule enforcement
- Database integrity

### Manual Testing Checklist

1. **Authentication**
   - [ ] Login with valid credentials
   - [ ] Login fails with invalid credentials
   - [ ] Account locks after failed attempts
   - [ ] Logout clears session

2. **Order Management**
   - [ ] Create order with valid data
   - [ ] Cannot create order with invalid product/party
   - [ ] Edit order quantity (increase/decrease)
   - [ ] Cannot reduce quantity below dispatched

3. **Dispatch Management**
   - [ ] Create dispatch within order limits
   - [ ] Cannot dispatch more than ordered
   - [ ] Multiple dispatches sum correctly
   - [ ] Order status updates automatically

4. **Reports and Exports**
   - [ ] Dashboard shows correct analytics
   - [ ] Party-wise report filters work
   - [ ] PDF export generates correctly
   - [ ] Excel export generates correctly

5. **Role-based Access**
   - [ ] Entry user can create orders/dispatches
   - [ ] View user can only view and report
   - [ ] Admin can manage users
   - [ ] Proper access denied messages

## Deployment

### Production Checklist

1. **Security**
   - [ ] Change default passwords
   - [ ] Set secure session secrets in `.env`
   - [ ] Enable HTTPS
   - [ ] Configure proper file permissions
   - [ ] Disable debug mode (`APP_DEBUG=false`)

2. **Database**
   - [ ] Create production database
   - [ ] Run migrations
   - [ ] Set up regular backups
   - [ ] Configure proper user permissions

3. **Web Server**
   - [ ] Configure virtual host
   - [ ] Set document root to `/public`
   - [ ] Enable URL rewriting
   - [ ] Configure error logging

4. **Performance**
   - [ ] Enable OPcache
   - [ ] Configure session storage (Redis/Memcached)
   - [ ] Set up database indexing
   - [ ] Enable gzip compression

### Shared Hosting Deployment

1. Upload files to hosting account
2. Create database via hosting control panel
3. Update `.env` with hosting database credentials
4. Run migrations via hosting file manager or SSH
5. Configure subdomain/directory to point to `/public`

## Troubleshooting

### Common Issues

**Database Connection Failed**
- Check database credentials in `.env`
- Ensure database server is running
- Verify database exists

**Permission Denied Errors**
- Check file permissions (755 for directories, 644 for files)
- Ensure web server can write to session directory
- Verify database user has proper privileges

**CSRF Token Mismatch**
- Ensure sessions are working
- Check session configuration
- Verify CSRF token is included in forms

**Export Generation Fails**
- Check PHP memory limit
- Ensure required extensions are installed
- Verify write permissions for temporary files

### Debug Mode

Enable debug mode in `.env`:
```
APP_DEBUG=true
```

This will show detailed error messages and stack traces.

## Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open Pull Request

### Coding Standards

- Follow PSR-12 coding style
- Add PHPDoc comments for classes and methods
- Write tests for new features
- Update documentation as needed

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support and questions:
- Create an issue in the repository
- Check the troubleshooting section
- Review the API documentation

---

**Built with ❤️ for efficient order and dispatch management**




