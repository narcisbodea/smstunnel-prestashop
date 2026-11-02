# Publishing SMSTunnel for PrestaShop

Nothing has been published yet. There are two places to publish:

1. **PrestaShop Addons Marketplace** (addons.prestashop.com): where PrestaShop merchants look for modules, and the only place the back office's own module catalogue shows.
2. **GitHub releases** (`narcisbodea/smstunnel-prestashop`): a free download of `smstunnel.zip`, linked from the README and from smstunnel.io. It can go out first, without waiting for the Addons review.

## Before every release

1. Set the version in `smstunnel/smstunnel.php` (`$this->version`), `smstunnel/config.xml` and `CHANGELOG.md`. A version that changes the database or the settings also needs `smstunnel/upgrade/upgrade-X.Y.Z.php` (a function `upgrade_module_X_Y_Z($module)` returning `true`).
2. Check and build:
   ```bash
   bash tools/check.sh                                  # php -l + unit tests on PHP 7.2, 7.4, 8.1, 8.5
   node tools/translations.mjs                          # translations complete for every wording
   tools/dev/vendor/bin/php-cs-fixer fix --dry-run      # PrestaShop coding standard
   bash tools/check-static.sh <ps8 container> <ps9 container>   # PHPStan
   bash tools/build.sh                                  # build/smstunnel.zip
   ```
3. Run the end-to-end tests on PrestaShop 8.1 and 9.2 (see README, "Development").

The zip must be called `smstunnel.zip`, hold one folder `smstunnel/` with `smstunnel.php` inside, and have no version number in its name. `tools/build.sh` builds exactly that (with the Composer autoloader in `vendor/` and an `index.php` in every folder).

## PrestaShop Addons

### 1. Seller account

- Sign in at addons.prestashop.com with a PrestaShop account and become a seller (contributor) from the **Sell** pages: company details for NARBOWEB SRL (address, VAT number), the payout details if the module is not free, and the acceptance of the marketplace vendor terms.
- Support for Addons customers goes through the Addons messaging; the listing may not point to another support channel.

### 2. Module key

When the product is created, Addons gives a **module key**. Add it to the constructor in `smstunnel/smstunnel.php`, then rebuild:

```php
$this->module_key = 'the 32-character key from your seller account';
```

### 3. Technical validation

- Run the zip through the **Validator** (validator.prestashop.com, same account) first: it checks the structure, security (escaping, SQL), coding standards and compatibility. Fix every error.
- What the Addons team checks by hand, and how this module meets it:

| Rule | Here |
| --- | --- |
| Zip, folder and main file share the technical name, lowercase | `smstunnel.zip` / `smstunnel/` / `smstunnel.php` |
| An `index.php` in every folder | yes, also in `vendor/` (added by the build) |
| License compatible with AFL/OSL (MIT accepted) | MIT, `LICENSE` in the zip |
| Code and wordings in English, then translated | yes; FR, ES, IT, RO, DE catalogues in `translations/` |
| Settings and classes prefixed with the module name | `SMSTUNNEL_*`, `SmsTunnel\PrestaShop\*`, table `PREFIX_smstunnel_log` |
| SQL values cast or escaped | `(int)` and `pSQL()` everywhere |
| HTML in templates, not PHP | Smarty templates in `views/templates/admin`; forms by `HelperForm` |
| No `serialize()`, no core table changes, no core file changes | none |
| Nothing downloaded after installation | none; the module only calls the SMSTunnel API |
| No PHP notice or warning with debug mode on | the end-to-end tests run with debug mode on |
| No debug code, no commented-out code | none |

- Review time: the technical review usually takes **5 to 10 working days**. Addons sends an email with the decision or the changes to make.

### 4. Product page

In the seller account: **Products > New > Add a Module**. It needs:

- **Name**: "SMSTunnel: SMS notifications" (Addons forbids "PrestaShop" and "module" in names).
- **Icon**: 57 × 57 px, `marketing/addons-icon-57.png`.
- **Images**: at least 3, ideally square. Use the back office screenshots in `D:\ANDROID\integrari\prestashop-screens\` (configuration page, recent texts, back office in French), cropped square.
- **Short description**, a **long description** with the features, at least one **benefit for the merchant** and one **benefit for the customer**. The texts of `site-page.md` can be reused.
- **Keywords**: at least 5 in every language of the page, for example: SMS, SMS notification, order status SMS, shipping notification, text message, tracking number SMS, Android phone, order alert.
- **Languages**: English, and as many of French, Spanish, Italian and German as you can provide; Addons reviews each language.
- **Countries**: all.
- **Compatibility**: PrestaShop 8.1.0 to 9.2.x (the module declares 8.1.0 to 9.99.99).
- **Changelog**: at least one entry (from `CHANGELOG.md`).
- **Price**: free or paid (your decision; the commission is in the vendor terms).
- **Documentation**: Addons prefers a PDF in the module's `docs/` folder. One can be made from the README's "Setup" and "Privacy and GDPR" parts.
- Addons asks for no email address or website URL on the product page or in the documentation. The module needs an SMSTunnel account, so say it in words ("an account at SMSTunnel and its free Android app"); the module's own page links to Google Play and to the dashboard.
- Optional: a demo back office (a profile limited to the module).

The product page review takes up to **10 working days per language**, after the technical approval. Once both pass, the module is online.

### 5. Updates

Upload the new zip on the product, with its changelog. A minor update goes through the Validator and a review of the changes (about a week); a major one through the full review.

## GitHub release

The repository is local only (branch `master`). To publish it on GitHub:

```bash
cd D:/ANDROID/integrari/smstunnel-prestashop
git branch -M main
gh repo create narcisbodea/smstunnel-prestashop --public --source . --remote origin \
  --description "SMSTunnel for PrestaShop: order texts from your own phone and your own number"
git push -u origin main
bash tools/build.sh
git tag -a v1.0.0 -m "SMSTunnel for PrestaShop 1.0.0"
git push origin v1.0.0
gh release create v1.0.0 build/smstunnel.zip --title "SMSTunnel for PrestaShop 1.0.0" \
  --notes "First version. Download smstunnel.zip and upload it in Modules > Module Manager > Upload a module. See CHANGELOG.md."
```

The README's download link (`releases/latest`) works as soon as the release exists. The smstunnel.io page (`site-page.md`) can then link to the same zip.
