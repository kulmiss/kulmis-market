<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupTools extends Command
{
    protected $signature = 'km:backup-key {--decrypt= : path of a .enc backup to decrypt} {--to= : output path}';

    protected $description = 'Generate a BACKUP_KEY, or decrypt a backup with --decrypt=file.';

    public function handle(): int
    {
        if ($file = $this->option('decrypt')) {
            $to = (string) ($this->option('to') ?: preg_replace('/\.enc$/', '', (string) $file));
            Backup::decryptFile((string) $file, $to, Backup::key());
            $this->info("Decrypted to $to (tar -xzf it to restore).");

            return self::SUCCESS;
        }
        $this->line('BACKUP_KEY=base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen()));
        $this->warn('Put this in .env and keep a copy somewhere safe (password manager). Without it backups cannot be opened.');

        return self::SUCCESS;
    }
}
