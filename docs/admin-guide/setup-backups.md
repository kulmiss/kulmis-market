---
title: Backups and restore
part: 1. Setup
order: 5
---
**Every night at 02:17** the shop makes an encrypted backup (database, payment receipts, uploads) when `BACKUP_KEY` is set. It keeps 14 days in `storage/app/backups`.

**Set it up once**
1. `php artisan km:backup-key` → copy the line into `.env`.
2. Save the key in your password manager. **Lose the key = lose the backups.**

**Every week**: download the newest file from `storage/app/backups` to your computer or cloud drive. A backup that only lives on the same server is lost with the server.

**Every month: test that a backup really opens**
```
php artisan km:backup-verify
```
You should see `Backup OK — it can be restored.` Anything else: fix it the same day.

**Real restore (after a disaster)**
1. `php artisan down`
2. `php artisan km:backup-key --decrypt=storage/app/backups/kulmis-….enc`
3. Unpack it: `tar -xzf kulmis-….tar.gz` → `database.sql`, `app/private`, `app/public`.
4. Load the database (`mysql … < database.sql`) and copy the two folders back into `storage/`.
5. `php artisan km:doctor`, then `php artisan up`.

**Check**: `php artisan km:doctor` turns red when there is no backup from the last 26 hours.
