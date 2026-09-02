<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Console;

use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Recovery path for the merchant API secret.
 *
 * The secret is revealed exactly once, at install. A lite install has no panel
 * to look it up in — and by design nothing can look it up, since only the
 * ciphertext and a fingerprint are stored. So when it is lost, the answer is
 * to mint a new one from the CLI.
 *
 * Rotation is immediate and total: every existing key for the gateway is
 * revoked in the same transaction, so any integration still signing with the
 * old secret starts failing on its next request. That is the point — but it
 * means this is not a routine command, hence the confirmation prompt.
 */
final class RotateApiKeyCommand extends Command
{
    protected $signature = 'cardpay:api-key:rotate
        {--label=Primary : Label stored against the new key}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Revoke the gateway API keys and mint a new pair (the secret is shown once)';

    public function handle(GatewayProvisioner $provisioner): int
    {
        try {
            $application = $provisioner->resolve();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $active = $application->apiKeys()->where('is_active', true)->count();

        if ($active > 0 && ! $this->option('force') && ! $this->confirm(
            "This revokes {$active} active key(s) for [{$application->slug}]. Any integration using them will stop working immediately. Continue?",
            false,
        )) {
            $this->line('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        $credentials = $provisioner->rotateApiKey($application, (string) $this->option('label'));

        $this->newLine();
        $this->warn(' New API credentials — shown ONCE, store them now:');
        $this->line('   Application key : '.$application->public_key);
        $this->line('   API public key  : '.$credentials->publicKey);
        $this->line('   API secret      : '.$credentials->secret);
        $this->newLine();

        return self::SUCCESS;
    }
}
