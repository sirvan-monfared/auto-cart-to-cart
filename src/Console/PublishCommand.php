<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Console;

use Illuminate\Console\Command;

/**
 * Publishes every overridable CardPay resource (config, views, lang, fonts)
 * so a host can customize without touching vendor. Published views live at
 * resources/views/vendor/cardpay and lang at lang/vendor/cardpay — Laravel
 * resolves those automatically ahead of the package originals.
 */
final class PublishCommand extends Command
{
    protected $signature = 'cardpay:publish {--force : Overwrite existing files}';

    protected $description = 'Publish CardPay config, views, translations, and fonts for customization';

    public function handle(): int
    {
        foreach (['cardpay-config', 'cardpay-views', 'cardpay-lang', 'cardpay-assets'] as $tag) {
            $this->components->info("Publishing [$tag]…");
            $this->call('vendor:publish', [
                '--tag' => $tag,
                '--force' => (bool) $this->option('force'),
            ]);
        }

        return self::SUCCESS;
    }
}
