# Mascardi Car Yard — Cron Jobs Setup Guide

This folder contains PHP scripts designed to run automatically on a schedule.

---

## Scripts

| Script | Frequency | Purpose |
|---|---|---|
| `daily_alerts.php` | Daily (morning) | Sends alerts for overdue jobs, low stock, unpaid invoices |
| `weekly_digest.php` | Weekly (Monday 7AM) | Sends executive summary email to super_admin / GM users |
| `meeting_reminders.php` | **Every 5 minutes** | Tops up recurring meeting series; sends meeting reminders (1 day before, 30 min before) by email and in-system |

> **`meeting_reminders.php` must run every 5 minutes, not daily.** One of its two
> reminders goes out 30 minutes before a meeting starts, so an hourly job would
> deliver it up to an hour late — after the meeting had already begun. Running it
> more often is safe: each reminder is claimed in the database before it is sent,
> so nobody is reminded twice however often the job runs.
>
> Recurring meetings with no end date also depend on this job. Their occurrences
> are created a rolling window ahead (120 days) and refilled on each run, so if
> the job stops, the calendar eventually runs dry.

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

# Meeting reminders — every 5 minutes (see the note above; daily is not enough)
*/5 * * * * /usr/bin/php /var/www/mascardi/scripts/cron/meeting_reminders.php >> /var/log/mascardi_cron.log 2>&1
```

### cPanel (Cron Jobs page)

On cPanel the PHP binary is usually `/usr/local/bin/php` and the path is under
your home directory. Add one job:

| Setting | Value |
|---|---|
| Common Settings | Every 5 Minutes (`*/5 * * * *`) |
| Command | `/usr/local/bin/php /home/USERNAME/public_html/scripts/cron/meeting_reminders.php` |

Replace `USERNAME` with your cPanel username. Leave the output unredirected the
first time so cPanel emails you the result, and check it once.

### Checking it works

Run it by hand first — it prints what it did and is safe to repeat:

```bash
php scripts/cron/meeting_reminders.php
# [2026-08-14 09:15:02] meeting_reminders: Created 12 occurrence(s); reminded 2 meeting(s): 6 in-system, 6 email. (412ms)
```

A super admin can also trigger it in a browser at
`/scripts/cron/meeting_reminders.php?verbose=1`. Every run is recorded in the
`cron_runs` table, so `SELECT * FROM cron_runs WHERE job_name='meeting_reminders'
ORDER BY ran_at DESC LIMIT 5` will show whether the schedule is actually firing.

Email needs SMTP configured under **Settings → Email**. Without it the in-system
notifications still go out and the email attempts are recorded as failed in
`meeting_reminders.error` rather than being silently dropped.

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
