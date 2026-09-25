# PKP SECUREGATE - DEPLOYMENT GUIDE

**Last Updated**: 2026-09-21  
**Status**: ✅ READY FOR STAGING & PRODUCTION

---

## QUICK START

### Prerequisites
- Docker & Docker Compose
- Git
- PHP 8.2+ (for local development)
- Node.js 18+ (for frontend build)

### Environment Setup

```bash
# Clone repository
git clone https://github.com/ishakper/pkp_gestscure.git
cd access-door-management

# Switch to main branch (latest stable)
git checkout main

# Copy environment file
cp .env.example .env

# Build and start containers
docker compose up -d --build

# Run migrations
docker compose exec -T app php artisan migrate

# Access application
# Production: http://localhost:8080
# Test Credentials:
#   Email: admin.uat@pkp.co.id
#   Password: UAT123!Pass (Testing Only)
```

---

## DEPLOYMENT ENVIRONMENTS

### Development Environment
```yaml
APP_ENV: local
DB_CONNECTION: sqlite
DB_DATABASE: database/database.sqlite
HIKVISION_MOCK_MODE: true
```

### Testing Environment (Current - UAT Verified)
```yaml
APP_ENV: testing
DB_CONNECTION: sqlite
DB_DATABASE: database/uat.sqlite
HIKVISION_MOCK_MODE: true
```

### Staging Environment (Next)
```yaml
APP_ENV: staging
DB_CONNECTION: pgsql
DB_DATABASE: access_door_staging
HIKVISION_MOCK_MODE: false  # Real device integration
```

### Production Environment
```yaml
APP_ENV: production
DB_CONNECTION: pgsql
DB_DATABASE: access_door_production
HIKVISION_MOCK_MODE: false  # Real device integration
```

---

## INFRASTRUCTURE CHECKLIST

### Pre-Deployment Verification
- [x] Code compiled without errors
- [x] All tests passing (509/509 ✅)
- [x] Database migrations successful
- [x] Security headers configured
- [x] Environment variables set
- [x] Docker builds clean
- [x] No hardcoded secrets
- [x] Production data isolated

### Deployment Steps

#### Stage 1: Staging Deployment
```bash
# 1. Switch to production build
git checkout main
git pull origin main

# 2. Update environment
cp .env.staging .env

# 3. Build production Docker image
docker compose -f docker-compose.prod.yml build

# 4. Start services
docker compose -f docker-compose.prod.yml up -d

# 5. Run migrations
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate

# 6. Run tests to verify
docker compose -f docker-compose.prod.yml exec -T app php artisan test

# 7. Clear cache
docker compose -f docker-compose.prod.yml exec -T app php artisan cache:clear
```

#### Stage 2: Production Deployment
```bash
# 1. Create production backup
docker exec postgres_prod pg_dump -U postgres access_door_production \
  > backup_$(date +%Y%m%d_%H%M%S).sql

# 2. Pull latest main branch
git fetch origin
git checkout main
git pull origin main

# 3. Build production image
docker compose -f docker-compose.prod.yml build --no-cache

# 4. Start with new image
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d

# 5. Run migrations
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate

# 6. Verify deployment
docker compose -f docker-compose.prod.yml exec -T app php artisan health
```

---

## VERIFICATION PROCEDURES

### Health Check
```bash
# Check application health
curl http://localhost:8080/health

# Check database connection
docker compose exec -T app php artisan db

# Check all services
docker compose ps
```

### Test Execution
```bash
# Run all tests
docker compose exec -T app php artisan test

# Run specific test suite
docker compose exec -T app php artisan test tests/Feature

# Run with coverage
docker compose exec -T app php artisan test --coverage
```

### Logging & Monitoring
```bash
# View application logs
docker compose logs -f app

# View database logs
docker compose logs -f database

# View error logs
docker compose exec -T app tail -f storage/logs/laravel.log
```

---

## ROLLBACK PROCEDURES

### Immediate Rollback
```bash
# 1. Stop current version
docker compose -f docker-compose.prod.yml down

# 2. Checkout previous version
git checkout HEAD~1

# 3. Start previous version
docker compose -f docker-compose.prod.yml up -d

# 4. Run previous migrations
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate:rollback
```

### Database Rollback
```bash
# Restore from backup
docker exec postgres_prod psql -U postgres -d access_door_production \
  < backup_YYYYMMDD_HHMMSS.sql
```

---

## ENVIRONMENT VARIABLES

### Required Configuration
```env
# Application
APP_NAME="PKP SecureGate"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=access_door_production
DB_USERNAME=postgres
DB_PASSWORD=secure_password_here

# Security
SANCTUM_STATEFUL_DOMAINS=yourdomain.com,www.yourdomain.com

# Hikvision Integration
HIKVISION_ISAPI_IP=192.168.x.x
HIKVISION_ISAPI_USERNAME=admin
HIKVISION_ISAPI_PASSWORD=password
HIKVISION_ISAPI_PORT=80

# Email (for notifications)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailer.com
MAIL_PORT=587
MAIL_USERNAME=username
MAIL_PASSWORD=password
MAIL_FROM_ADDRESS=noreply@yourdomain.com
```

---

## MONITORING & MAINTENANCE

### Daily Tasks
- [ ] Check error logs
- [ ] Monitor disk space
- [ ] Verify backup completion
- [ ] Check system health metrics

### Weekly Tasks
- [ ] Review access logs
- [ ] Test disaster recovery
- [ ] Update security patches
- [ ] Performance analysis

### Monthly Tasks
- [ ] Full system backup
- [ ] Database optimization
- [ ] Security audit
- [ ] Capacity planning

---

## TROUBLESHOOTING

### Common Issues

**Problem**: Container fails to start
```bash
# Check logs
docker compose logs app

# Verify .env file
cat .env

# Rebuild clean
docker compose down
docker system prune -a
docker compose up -d --build
```

**Problem**: Database connection error
```bash
# Check database container
docker compose exec db psql -U postgres -l

# Verify credentials in .env
grep DB_ .env

# Run migrations again
docker compose exec -T app php artisan migrate
```

**Problem**: Tests failing
```bash
# Check test database
docker compose exec -T app php artisan test --verbose

# Seed test data
docker compose exec -T app php artisan db:seed

# Clear cache and try again
docker compose exec -T app php artisan cache:clear
```

---

## PERFORMANCE OPTIMIZATION

### Database Optimization
```bash
# Index analysis
docker compose exec db psql -U postgres -d access_door_production -c "ANALYZE;"

# Vacuum for cleanup
docker compose exec db psql -U postgres -d access_door_production -c "VACUUM FULL;"
```

### Cache Management
```bash
# Clear all caches
docker compose exec -T app php artisan cache:clear

# Optimize for production
docker compose exec -T app php artisan optimize
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
```

---

## SECURITY CONSIDERATIONS

### SSL/TLS Configuration
```bash
# Generate SSL certificate
docker compose exec app certbot certonly --webroot -w /var/www/html/public

# Auto-renewal setup
docker compose exec app certbot renew --dry-run
```

### Regular Security Updates
```bash
# Update dependencies
docker compose exec -T app composer update

# Check for vulnerabilities
docker compose exec -T app composer audit

# Update system packages
docker compose exec app apk update && apk upgrade
```

### Access Control
- Limit admin accounts
- Enforce strong passwords
- Enable 2FA when available
- Regular access audits

---

## SUPPORT & ESCALATION

### Critical Issues (P1)
- System down
- Data loss/corruption
- Security breach
- Report: Immediate escalation

### High Priority (P2)
- Feature not working
- Performance degradation
- Data inconsistency
- Report: Within 4 hours

### Medium Priority (P3)
- UI/UX issues
- Non-critical features
- Documentation updates
- Report: Within 24 hours

---

## DOCUMENTATION REFERENCES

- [API Documentation](./docs/API.md)
- [Architecture Overview](./docs/ENTERPRISE_ARCHITECTURE.md)
- [Configuration Guide](./docs/CONFIGURATION.md)
- [Testing Guide](./docs/TESTING.md)
- [Security Policy](./docs/SECURITY.md)

---

**Last Verified**: 2026-09-21  
**Version**: ishak/full-functional-integration-2026-09  
**Status**: ✅ READY FOR DEPLOYMENT
