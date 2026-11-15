<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Encrypted backup: database dump + private files (payment proofs) + public
 * uploads, packed and encrypted with libsodium (XChaCha20-Poly1305 stream)
 * using BACKUP_KEY. Old backups are pruned. Copy the files off the server!
 */
class Backup extends Command
{
    protected $signature = 'km:backup {--keep=14 : days to keep} {--no-files : database only} {--connection= : database connection (default: the app one)}';

    protected $description = 'Create an encrypted backup in storage/app/backups.';

    public function handle(): int
    {
        $key = self::key();
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0700);
        $work = storage_path('app/backups/tmp-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($work, 0700);

        try {
            $this->dumpDatabase($work.'/database.sql');
            $tar = $work.'/backup.tar.gz';
            $paths = ['database.sql'];
            $cmd = ['tar', '-czf', $tar, '-C', $work, 'database.sql'];
            if (! $this->option('no-files')) {
                foreach (['app/private', 'app/public'] as $rel) {
                    if (is_dir(storage_path($rel))) {
                        array_push($cmd, '-C', storage_path(), $rel);
                        $paths[] = $rel;
                    }
                }
            }
            Process::run($cmd)->throw();
            $out = $dir.'/kulmis-'.now()->format('Ymd-His').'.tar.gz.enc';
            self::encryptFile($tar, $out, $key);
            chmod($out, 0600);
            $this->info('Backup written: '.$out.' ('.number_format((int) filesize($out) / 1024, 0).' KB)');
        } finally {
            File::deleteDirectory($work);
        }

        $cutoff = now()->subDays((int) $this->option('keep'))->getTimestamp();
        foreach (File::glob($dir.'/kulmis-*.enc') as $f) {
            if (filemtime($f) < $cutoff) {
                File::delete($f);
            }
        }

        return self::SUCCESS;
    }

    private function dumpDatabase(string $to): void
    {
        $c = config('database.connections.'.($this->option('connection') ?: config('database.default')));
        match ($c['driver']) {
            'mysql', 'mariadb' => Process::env(['MYSQL_PWD' => (string) $c['password']])->timeout(600)
                ->run(['sh', '-c', 'mysqldump --single-transaction --quick --routines --no-tablespaces -h "$0" -P "$1" -u "$2" "$3" > "$4"',
                    (string) $c['host'], (string) $c['port'], (string) $c['username'], (string) $c['database'], $to])->throw(),
            'sqlite' => File::copy((string) $c['database'], $to),
            default => throw new RuntimeException('Backups support MySQL/MariaDB and SQLite.'),
        };
    }

    public static function key(): string
    {
        $raw = (string) config('shop.backup_key');
        $key = base64_decode(str_replace('base64:', '', $raw), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Set BACKUP_KEY (run: php artisan km:backup-key).');
        }

        return $key;
    }

    public static function encryptFile(string $in, string $out, string $key): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        fwrite($dst, 'KMB1'.$header);
        while (! feof($src)) {
            $chunk = (string) fread($src, 1 << 20);
            $tag = feof($src) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($dst, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
        }
        fclose($src);
        fclose($dst);
    }

    public static function decryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        if (fread($src, 4) !== 'KMB1') {
            throw new RuntimeException('Not a Kulmis backup file.');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string) fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
        $dst = fopen($out, 'wb');
        $done = false;
        while (! feof($src) && ! $done) {
            $chunk = (string) fread($src, (1 << 20) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($chunk === '') {
                break;
            }
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            if ($res === false) {
                throw new RuntimeException('Backup is damaged or the key is wrong.');
            }
            [$plain, $tag] = $res;
            fwrite($dst, $plain);
            $done = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        fclose($src);
        fclose($dst);
        if (! $done) {
            throw new RuntimeException('Backup is incomplete.');
        }
    }
}
