<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use Throwable;

/**
 * Restore test: proves the newest (or a given) backup can really be opened
 * and restored. Decrypts it, unpacks it, checks the key tables are there and
 * that the ledger in the backup balances to zero. With --restore-to it also
 * loads the dump into a SEPARATE scratch database (never the live one).
 */
class BackupVerify extends Command
{
    protected $signature = 'km:backup-verify {--file= : backup to check (default: newest)} {--restore-to= : scratch DB connection to load a MySQL dump into}';

    protected $description = 'Restore test for an encrypted backup (decrypt, unpack, check tables and ledger).';

    public const REQUIRED_TABLES = ['users', 'orders', 'payments', 'journal_entries', 'ledger_postings', 'ledger_accounts', 'payout_requests', 'activity_log'];

    public function handle(): int
    {
        $file = (string) ($this->option('file') ?: collect(File::glob(storage_path('app/backups/kulmis-*.enc')))->sort()->last());
        if ($file === '' || ! is_file($file)) {
            $this->error('No backup found. Run: php artisan km:backup');

            return self::FAILURE;
        }
        $work = storage_path('app/backups/verify-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($work, 0700);
        try {
            Backup::decryptFile($file, "$work/backup.tar.gz", Backup::key());
            $this->line('  ✔ decrypted '.basename($file));
            Process::run(['tar', '-xzf', "$work/backup.tar.gz", '-C', $work])->throw();
            $this->line('  ✔ unpacked: '.implode(', ', array_map('basename', File::glob("$work/*"))));
            $sql = "$work/database.sql";
            if (! is_file($sql)) {
                throw new \RuntimeException('database.sql is missing from the backup.');
            }
            $isSqlite = str_starts_with((string) file_get_contents($sql, false, null, 0, 16), 'SQLite format 3');
            [$tables, $ledgerSum, $users] = $isSqlite ? $this->checkSqlite($sql) : $this->checkMysqlDump($sql);
            $missing = array_diff(self::REQUIRED_TABLES, $tables);
            if ($missing) {
                throw new \RuntimeException('Missing tables: '.implode(', ', $missing));
            }
            $this->line('  ✔ all key tables present');
            if ($ledgerSum !== null) {
                if ($ledgerSum !== 0) {
                    throw new \RuntimeException("Ledger in the backup does not balance (off by $ledgerSum).");
                }
                $this->line("  ✔ ledger balances (sum of postings = 0), $users users");
            }
            $this->info('Backup OK — it can be restored.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup check FAILED: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @return array{0: list<string>, 1: int, 2: int} */
    private function checkSqlite(string $path): array
    {
        $pdo = new PDO('sqlite:'.$path);
        $tables = $pdo->query("select name from sqlite_master where type='table'")->fetchAll(PDO::FETCH_COLUMN);

        return [array_map('strval', $tables), (int) $pdo->query('select coalesce(sum(amount),0) from ledger_postings')->fetchColumn(),
            (int) $pdo->query('select count(*) from users')->fetchColumn()];
    }

    /** @return array{0: list<string>, 1: ?int, 2: int} */
    private function checkMysqlDump(string $path): array
    {
        preg_match_all('/^CREATE TABLE `([^`]+)`/m', (string) file_get_contents($path), $m);
        $tables = $m[1];
        $conn = $this->option('restore-to');
        if (! $conn) {
            $this->line('  (structure checked; add --restore-to=<scratch connection> to load it and check the ledger)');

            return [$tables, null, 0];
        }
        $c = config("database.connections.$conn");
        $live = config('database.connections.'.config('database.default'));
        if (! $c || ($c['database'] === $live['database'] && $c['host'] === $live['host'])) {
            throw new \RuntimeException('--restore-to must be a separate scratch database, never the live one.');
        }
        Process::env(['MYSQL_PWD' => (string) $c['password']])->timeout(900)
            ->run(['sh', '-c', 'mysql -h "$0" -P "$1" -u "$2" "$3" < "$4"', (string) $c['host'], (string) $c['port'], (string) $c['username'], (string) $c['database'], $path])->throw();
        $this->line("  ✔ restored into [$conn]");
        $db = DB::connection($conn);

        return [$tables, (int) $db->table('ledger_postings')->sum('amount'), $db->table('users')->count()];
    }
}
