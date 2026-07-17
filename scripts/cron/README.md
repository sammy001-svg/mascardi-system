# Mascardi Car Yard — Cron Jobs Setup Guide

This folder contains PHP scripts designed to run automatically on a schedule.

---

## Scripts

| Script | Frequency | Purpose |
|---|---|---|
| `daily_alerts.php` | Daily (morning) | Sends alerts for overdue jobs, low stock, unpaid invoices |
| `weekly_digest.php` | Weekly (Monday 7AM) | Sends executive summary email to super_admin / GM users |

---

## Setup on Windows (XAMPP)

### Option A — Windows Task Scheduler (Recommended)

1. Open **Task Scheduler** (search in Start Menu)
2. Click **"Create Basic Task"**
3. Set the name (e.g. `Mascardi Daily Alerts`)
4. Set the trigger: **Daily**, at **6:00 AM**
5. Set the action: **Start a program**
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `"C:\Mascardi System\mascardi-system\scripts\cron\daily_alerts.php"`
   - Start in: `C:\Mascardi System\mascardi-system`
6. Finish and enable the task

Repeat for `weekly_digest.php`, but set trigger to **Weekly → Monday**.

### Option B — bat file + Task Scheduler

Create `run_daily_alerts.bat`:
```bat
@echo off
"C:\xampp\php\php.exe" "C:\Mascardi System\mascardi-system\scripts\cron\daily_alerts.php" >> "C:\Mascardi System\logs\daily_alerts.log" 2>&1
```

Then schedule the `.bat` file in Task Scheduler.

---

## Setup on Linux / cPanel

Add to crontab (`crontab -e`):

```cron
# Daily alerts at 6:00 AM
0 6 * * * /usr/bin/php /var/www/mascardi/scripts/cron/daily_alerts.php >> /var/log/mascardi_cron.log 2>&1

# Weekly digest every Monday at 7:00 AM
0 7 * * 1 /usr/bin/php /var/www/mascardi/scripts/cron/weekly_digest.php >> /var/log/mascardi_cron.log 2>&1
```

---

## Monitoring

All cron executions are logged to the `cron_runs` database table.

**View recent runs in phpMyAdmin:**
```sql
SELECT * FROM cron_runs ORDER BY ran_at DESC LIMIT 20;
```

**Check for errors:**
```sql
SELECT * FROM cron_runs WHERE status = 'error' ORDER BY ran_at DESC;
```

---

## Email Configuration

The cron scripts use PHP's built-in `mail()` function. For reliable delivery:

1. Configure SMTP in `php.ini` (XAMPP: `C:\xampp\php\php.ini`):
   ```ini
   [mail function]
   SMTP = smtp.gmail.com
   smtp_port = 587
   sendmail_from = noreply@mascardi.co.ke
   ```

2. Or install **Stunnel** + **SendMail** for authenticated SMTP.

3. Best practice for production: replace `mail()` with a proper SMTP library
   (PHPMailer is already included via `includes/mailer.php`).
