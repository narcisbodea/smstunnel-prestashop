<?php

/**
 * PrestaShop's coding standard (prestashop/php-dev-tools), for the module code:
 *   tools/dev/vendor/bin/php-cs-fixer fix --dry-run --diff
 */
$config = new class() extends PrestaShop\CodingStandards\CsFixer\Config {
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            // The module runs on PHP 7.2+: no trailing comma in parameter lists or calls (PHP 8.0 syntax).
            'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        ]);
    }
};
$config
    ->setUsingCache(false)
    ->getFinder()
    ->in([__DIR__ . '/smstunnel', __DIR__ . '/tests/unit', __DIR__ . '/tests/e2e/php'])
    ->exclude(['vendor', 'translations']);

return $config;
