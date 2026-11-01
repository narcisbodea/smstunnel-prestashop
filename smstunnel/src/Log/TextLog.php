<?php
/**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace SmsTunnel\PrestaShop\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The texts the module sent or tried to send, newest first, in the table PREFIX_smstunnel_log.
 *
 * Each order text has a key (order + event + recipient). The key is unique, so a hook that runs twice
 * for the same event sends one text: the second run finds the key and stops, unless the first attempt
 * failed or was skipped, in which case it tries again.
 */
final class TextLog
{
    const TABLE = 'smstunnel_log';

    /** The log keeps the newest rows only. */
    const KEEP = 1000;

    const SENDING = 'sending';
    const SENT = 'sent';
    const QUEUED = 'queued';
    const FAILED = 'failed';
    const SKIPPED = 'skipped';

    /** A row left in "sending" this long (a request that died midway) can be tried again. */
    const STALE_SECONDS = 600;

    public static function install(): bool
    {
        return (bool) \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
                `id_smstunnel_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `dedupe_key` VARCHAR(128) NULL DEFAULT NULL,
                `event` VARCHAR(16) NOT NULL,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_order_state` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `recipient` VARCHAR(40) NOT NULL DEFAULT \'\',
                `message` TEXT NULL,
                `status` VARCHAR(16) NOT NULL,
                `message_id` VARCHAR(64) NULL DEFAULT NULL,
                `error` VARCHAR(255) NULL DEFAULT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_smstunnel_log`),
                UNIQUE KEY `dedupe_key` (`dedupe_key`),
                KEY `id_order` (`id_order`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function uninstall(): bool
    {
        return (bool) \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table() . '`');
    }

    /**
     * Reserves a text before it is sent.
     *
     * @param array{dedupe_key: string|null, event: string, id_order: int, id_order_state: int, id_shop: int, recipient: string, message: string} $text
     *
     * @return int the row id, or 0 when this text was already sent or is being sent
     */
    public function claim(array $text): int
    {
        $db = \Db::getInstance();
        $now = date('Y-m-d H:i:s');
        $key = isset($text['dedupe_key']) && $text['dedupe_key'] !== '' ? (string) $text['dedupe_key'] : null;
        $recipient = substr((string) $text['recipient'], 0, 40);

        $inserted = $db->execute(
            'INSERT IGNORE INTO `' . self::table() . '`
                (`dedupe_key`, `event`, `id_order`, `id_order_state`, `id_shop`, `recipient`, `message`, `status`, `date_add`, `date_upd`)
            VALUES (' . ($key === null ? 'NULL' : '\'' . pSQL($key) . '\'') . ',
                \'' . pSQL((string) $text['event']) . '\',
                ' . (int) $text['id_order'] . ',
                ' . (int) $text['id_order_state'] . ',
                ' . (int) $text['id_shop'] . ',
                \'' . pSQL($recipient) . '\',
                \'' . pSQL((string) $text['message'], true) . '\',
                \'' . self::SENDING . '\',
                \'' . pSQL($now) . '\',
                \'' . pSQL($now) . '\')'
        );
        if ($inserted && $db->Affected_Rows() > 0) {
            $id = (int) $db->Insert_ID();
            if ($id % 50 === 0) {
                $this->prune();
            }

            return $id;
        }
        if ($key === null) {
            return 0;
        }

        // Already there: send again only when the earlier attempt did not go out.
        $id = (int) $db->getValue('SELECT `id_smstunnel_log` FROM `' . self::table() . '` WHERE `dedupe_key` = \'' . pSQL($key) . '\'', false);
        if ($id <= 0) {
            return 0;
        }
        $retried = $db->execute(
            'UPDATE `' . self::table() . '`
            SET `status` = \'' . self::SENDING . '\', `attempts` = `attempts` + 1, `recipient` = \'' . pSQL($recipient) . '\',
                `message` = \'' . pSQL((string) $text['message'], true) . '\', `message_id` = NULL, `error` = NULL,
                `date_upd` = \'' . pSQL($now) . '\'
            WHERE `id_smstunnel_log` = ' . $id . '
                AND (`status` IN (\'' . self::FAILED . '\', \'' . self::SKIPPED . '\')
                    OR (`status` = \'' . self::SENDING . '\' AND `date_upd` < \'' . pSQL(date('Y-m-d H:i:s', time() - self::STALE_SECONDS)) . '\'))'
        );

        return $retried && $db->Affected_Rows() > 0 ? $id : 0;
    }

    /**
     * Records how a reserved text ended.
     */
    public function finish(int $id, string $status, string $messageId = '', string $error = '')
    {
        \Db::getInstance()->execute(
            'UPDATE `' . self::table() . '`
            SET `status` = \'' . pSQL($status) . '\',
                `message_id` = ' . ($messageId === '' ? 'NULL' : '\'' . pSQL(substr($messageId, 0, 64)) . '\'') . ',
                `error` = ' . ($error === '' ? 'NULL' : '\'' . pSQL(self::cut($error, 255)) . '\'') . ',
                `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
            WHERE `id_smstunnel_log` = ' . (int) $id
        );
    }

    /**
     * The newest texts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 20, int $idShop = 0): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT l.*, o.`reference`
            FROM `' . self::table() . '` l
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = l.`id_order`
            ' . ($idShop > 0 ? 'WHERE l.`id_shop` IN (0, ' . (int) $idShop . ')' : '') . '
            ORDER BY l.`id_smstunnel_log` DESC
            LIMIT ' . max(1, min(200, $limit))
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * The last text that did not go out because of an error, if any.
     *
     * @return array<string, mixed>|null
     */
    public function lastFailure(int $idShop = 0)
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . self::table() . '`
            WHERE `status` = \'' . self::FAILED . '\'' . ($idShop > 0 ? ' AND `id_shop` IN (0, ' . (int) $idShop . ')' : '') . '
            ORDER BY `id_smstunnel_log` DESC',
            false
        );

        return is_array($row) ? $row : null;
    }

    /**
     * The texts about one customer's orders, for a GDPR data export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCustomer(int $idCustomer): array
    {
        if ($idCustomer <= 0) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT l.`date_add`, o.`reference` AS `order_reference`, l.`event`, l.`recipient`, l.`message`, l.`status`
            FROM `' . self::table() . '` l
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = l.`id_order`
            WHERE o.`id_customer` = ' . (int) $idCustomer . ' AND l.`event` = \'status\'
            ORDER BY l.`id_smstunnel_log` DESC'
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Deletes the texts about one customer's orders (GDPR erasure).
     */
    public function deleteForCustomer(int $idCustomer): bool
    {
        if ($idCustomer <= 0) {
            return true;
        }

        return (bool) \Db::getInstance()->execute(
            'DELETE l FROM `' . self::table() . '` l
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = l.`id_order`
            WHERE o.`id_customer` = ' . (int) $idCustomer
        );
    }

    /**
     * Keeps the newest KEEP rows.
     */
    public function prune()
    {
        $db = \Db::getInstance();
        // executeS, not getValue: getValue() appends its own LIMIT 1.
        $rows = $db->executeS(
            'SELECT `id_smstunnel_log` FROM `' . self::table() . '` ORDER BY `id_smstunnel_log` DESC LIMIT ' . (self::KEEP - 1) . ', 1',
            true,
            false
        );
        $oldestKept = is_array($rows) && isset($rows[0]['id_smstunnel_log']) ? (int) $rows[0]['id_smstunnel_log'] : 0;
        if ($oldestKept > 0) {
            $db->execute('DELETE FROM `' . self::table() . '` WHERE `id_smstunnel_log` < ' . $oldestKept);
        }
    }

    private static function table(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    private static function cut(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
