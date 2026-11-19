<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Read-only access to the database of a local test site, for the checks in tests/.
 * Not part of the package.
 *
 * Only SELECT, and only on the tables the checks are allowed to read. Anything else
 * is refused here, before it reaches the database.
 */

final class SiteDb
{
    /**
     * Tables that may be read, without the prefix.
     */
    private const TABLES = ['mucronixregistration_regions', 'user_profiles', 'users', 'schemas'];

    private \PDO $pdo;

    private string $prefix;

    /**
     * Connects with the settings of the site's configuration.php.
     *
     * @param   string  $site  Folder of the site.
     */
    public function __construct(string $site)
    {
        $file = rtrim($site, '/\\') . '/configuration.php';

        if (!is_file($file)) {
            throw new \RuntimeException("No configuration.php in $site");
        }

        // The file only declares the class JConfig with the settings.
        if (!class_exists('JConfig', false)) {
            require $file;
        }

        $config       = new \JConfig();
        $this->prefix = $config->dbprefix;

        [$host, $port] = array_pad(explode(':', $config->host, 2), 2, '3306');

        $this->pdo = new \PDO(
            "mysql:host=$host;port=$port;dbname={$config->db};charset=utf8mb4",
            $config->user,
            $config->password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );
    }

    /**
     * Runs a SELECT. Table names are written as #__name.
     *
     * @param   string  $sql     The query.
     * @param   array   $params  Bound values.
     *
     * @return  array  Rows.
     */
    public function select(string $sql, array $params = []): array
    {
        if (!preg_match('/^\s*SELECT\s/i', $sql) || preg_match('/;\s*\S/', $sql)) {
            throw new \LogicException('Only a single SELECT is allowed: ' . $sql);
        }

        preg_match_all('/#__(\w+)/', $sql, $m);

        foreach ($m[1] as $table) {
            if (!in_array($table, self::TABLES, true)) {
                throw new \LogicException("Reading #__$table is not allowed");
            }
        }

        if (preg_match('/\bFROM\s+(?!#__|\()/i', $sql) || preg_match('/\bJOIN\s+(?!#__)/i', $sql)) {
            throw new \LogicException('Tables must be written as #__name: ' . $sql);
        }

        $statement = $this->pdo->prepare(str_replace('#__', $this->prefix, $sql));
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * The settings of this plugin, the params column of its one row in #__extensions.
     * The site owner allowed this single read; nothing else of that table is read.
     */
    public function pluginParams(): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT params FROM ' . $this->prefix . "extensions WHERE type = 'plugin' AND folder = 'user' AND element = 'mucronixregistration'"
        );
        $statement->execute();

        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * First column of the first row, or null.
     */
    public function value(string $sql, array $params = []): mixed
    {
        $rows = $this->select($sql, $params);

        return $rows ? reset($rows[0]) : null;
    }

    /**
     * Whether a table exists: a SELECT on the table itself, an error means it is not there.
     */
    public function tableExists(string $table): bool
    {
        try {
            $this->select("SELECT 1 FROM #__$table LIMIT 1");

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Profile values of a user under the plugin keys, without the prefix.
     */
    public function profile(int $userId): array
    {
        $rows = $this->select(
            "SELECT profile_key, profile_value FROM #__user_profiles WHERE user_id = ? AND profile_key LIKE 'mucronixregistration.%' ORDER BY ordering",
            [$userId]
        );

        $result = [];

        foreach ($rows as $row) {
            $result[substr($row['profile_key'], strlen('mucronixregistration.'))] = $row['profile_value'];
        }

        return $result;
    }
}
