<?php

/**
 * @package     Mucronix Registration
 * @subpackage  plg_user_mucronixregistration
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Mucronix\Plugin\User\MucronixRegistration\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Countries and regions table together with the plugin settings that decide
 * which of them are offered.
 *
 * Countries are ISO 3166-1 alpha-2 codes (UA), regions are ISO 3166-2 codes (UA-53).
 *
 * @since  1.0.0
 */
final class RegionDirectory
{
    /**
     * The table of countries and regions.
     *
     * @since  1.0.0
     */
    public const TABLE = '#__mucronixregistration_regions';

    /**
     * Name column for a language tag. Other languages use the English name.
     *
     * @since  1.0.0
     */
    private const NAME_COLUMNS = [
        'uk-UA' => 'name_uk',
        'ru-RU' => 'name_ru',
    ];

    /**
     * Locales for sorting names.
     *
     * @since  1.0.0
     */
    private const COLLATOR_LOCALES = [
        'uk-UA' => 'uk_UA',
        'ru-RU' => 'ru_RU',
    ];

    /**
     * Cache of the published countries: code => true.
     *
     * @var    array<string, bool>|null
     * @since  1.0.0
     */
    private ?array $countries = null;

    /**
     * Cache of the countries that have published regions: code => true.
     *
     * @var    array<string, bool>|null
     * @since  1.0.0
     */
    private ?array $countriesWithRegions = null;

    /**
     * Constructor.
     *
     * @param   DatabaseInterface  $db      The database driver.
     * @param   Registry           $params  The plugin parameters.
     *
     * @since   1.0.0
     */
    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
    }

    /**
     * Creates the directory with the parameters of the installed plugin.
     * Used by form fields and rules, which have no access to the plugin object.
     *
     * @param   DatabaseInterface  $db  The database driver.
     *
     * @return  self
     *
     * @since   1.0.0
     */
    public static function fromPlugin(DatabaseInterface $db): self
    {
        $plugin = PluginHelper::getPlugin('user', 'mucronixregistration');
        $params = new Registry(\is_object($plugin) ? ($plugin->params ?? '') : '');

        return new self($db, $params);
    }

    /**
     * Normalises a submitted code: trimmed, upper case, or an empty string
     * when it cannot be a country or region code at all.
     *
     * @param   mixed  $value  The submitted value.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function normaliseCode(mixed $value): string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return '';
        }

        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $value) ? $value : '';
    }

    /**
     * Splits a list of codes typed by the administrator ("UA, PL").
     *
     * @param   string  $value  The list.
     *
     * @return  string[]  Upper-case tokens in the given order, without repeats. Not checked against the table.
     *
     * @since   1.0.0
     */
    public static function splitCodes(string $value): array
    {
        $tokens = preg_split('/[\s,;]+/', strtoupper($value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($tokens ?: []));
    }

    /**
     * Whether the code is a published country of the table, enabled or not.
     *
     * @param   string  $code  Country code.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    public function countryExists(string $code): bool
    {
        return isset($this->loadCountries()[$code]);
    }

    /**
     * Whether the country is offered by the settings.
     *
     * @param   string  $code  Country code.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    public function isCountryEnabled(string $code): bool
    {
        if (!$this->countryExists($code)) {
            return false;
        }

        $selected = $this->selectedCountries();

        return $selected === null || \in_array($code, $selected, true);
    }

    /**
     * Whether regions are offered for the country: it has regions and the settings allow them.
     *
     * @param   string  $code  Country code.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    public function regionsEnabled(string $code): bool
    {
        if (!isset($this->loadCountriesWithRegions()[$code])) {
            return false;
        }

        if ($this->params->get('regions_mode', 'all') !== 'selected') {
            return true;
        }

        return \in_array($code, $this->paramCodes('region_countries'), true);
    }

    /**
     * Whether the region is a published region of the country.
     *
     * @param   string  $region   Region code.
     * @param   string  $country  Country code.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    public function isRegionOf(string $region, string $country): bool
    {
        if ($region === '' || $country === '') {
            return false;
        }

        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('code') . ' = :code')
            ->where($this->db->quoteName('country_code') . ' = :country')
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('region'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->bind(':code', $region)
            ->bind(':country', $country);

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Name of a country or region in the given language. Disabled entries
     * still have a name: a value saved earlier is always shown.
     *
     * @param   string  $code  Country or region code.
     * @param   string  $tag   Language tag, e.g. uk-UA.
     *
     * @return  string  The name, or an empty string for an unknown code.
     *
     * @since   1.0.0
     */
    public function name(string $code, string $tag): string
    {
        if ($code === '') {
            return '';
        }

        $query = $this->db->createQuery()
            ->select($this->nameExpression($tag) . ' AS ' . $this->db->quoteName('name'))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('code') . ' = :code')
            ->bind(':code', $code);

        return (string) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Countries for the country list: the countries from "Countries at the top"
     * in their order, then the others alphabetically in the given language.
     *
     * @param   string  $tag   Language tag.
     * @param   string  $keep  A saved value that stays in the list even if it is disabled now.
     *
     * @return  array<string, string>  code => name
     *
     * @since   1.0.0
     */
    public function countryOptions(string $tag, string $keep = ''): array
    {
        $all     = $this->allCountries($tag);
        $options = [];

        foreach ($all as $code => $name) {
            if ($this->isCountryEnabled($code) || $code === $keep) {
                $options[$code] = $name;
            }
        }

        $top = [];

        foreach (self::splitCodes((string) $this->params->get('top_countries', '')) as $code) {
            if (isset($options[$code])) {
                $top[$code] = $options[$code];
                unset($options[$code]);
            }
        }

        return $top + $options;
    }

    /**
     * All published countries, alphabetically in the given language.
     *
     * @param   string   $tag          Language tag.
     * @param   boolean  $withRegions  Only countries that have regions.
     *
     * @return  array<string, string>  code => name
     *
     * @since   1.0.0
     */
    public function allCountries(string $tag, bool $withRegions = false): array
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('code'), $this->nameExpression($tag) . ' AS ' . $this->db->quoteName('name')])
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('country'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->order($this->db->quoteName('name') . ' ASC');

        $list = $this->db->setQuery($query)->loadAssocList('code', 'name');

        if ($withRegions) {
            $list = array_intersect_key($list, $this->loadCountriesWithRegions());
        }

        return $this->sort($list, $tag);
    }

    /**
     * Regions for the region list of a country.
     *
     * @param   string  $country  Country code.
     * @param   string  $tag      Language tag.
     * @param   string  $keep     A saved region that stays in the list even if regions are disabled now.
     *
     * @return  array<string, string>  code => name
     *
     * @since   1.0.0
     */
    public function regionOptions(string $country, string $tag, string $keep = ''): array
    {
        if ($country === '') {
            return [];
        }

        if (!$this->isCountryEnabled($country) || !$this->regionsEnabled($country)) {
            if ($keep !== '' && $this->isRegionOf($keep, $country)) {
                return [$keep => $this->name($keep, $tag)];
            }

            return [];
        }

        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('code'), $this->nameExpression($tag) . ' AS ' . $this->db->quoteName('name')])
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('country_code') . ' = :country')
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('region'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->order($this->db->quoteName('name') . ' ASC')
            ->bind(':country', $country);

        return $this->sort($this->db->setQuery($query)->loadAssocList('code', 'name'), $tag);
    }

    /**
     * The countries chosen in "Only selected", or null when all are offered.
     * An empty selection counts as all: otherwise nobody could register.
     *
     * @return  string[]|null
     *
     * @since   1.0.0
     */
    private function selectedCountries(): ?array
    {
        if ($this->params->get('countries_mode', 'all') !== 'selected') {
            return null;
        }

        $codes = $this->paramCodes('countries');

        return $codes ?: null;
    }

    /**
     * A list parameter as upper-case codes.
     *
     * @param   string  $name  Parameter name.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function paramCodes(string $name): array
    {
        $value = $this->params->get($name, []);

        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map([self::class, 'normaliseCode'], $value)));
    }

    /**
     * SQL expression for the name in a language, with the English name for an empty translation.
     *
     * @param   string  $tag  Language tag.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function nameExpression(string $tag): string
    {
        $column = self::NAME_COLUMNS[$tag] ?? null;

        if ($column === null) {
            return $this->db->quoteName('name_en');
        }

        return 'COALESCE(NULLIF(' . $this->db->quoteName($column) . ", ''), " . $this->db->quoteName('name_en') . ')';
    }

    /**
     * Sorts names by the rules of the language when the intl extension is there.
     * Without it the order of the database is kept, which is already alphabetical.
     *
     * @param   array<string, string>  $list  code => name
     * @param   string                 $tag   Language tag.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    private function sort(array $list, string $tag): array
    {
        if (!class_exists(\Collator::class)) {
            return $list;
        }

        $collator = new \Collator(self::COLLATOR_LOCALES[$tag] ?? 'en_GB');

        uasort($list, static fn(string $a, string $b): int => (int) $collator->compare($a, $b));

        return $list;
    }

    /**
     * Published countries.
     *
     * @return  array<string, bool>
     *
     * @since   1.0.0
     */
    private function loadCountries(): array
    {
        if ($this->countries === null) {
            $query = $this->db->createQuery()
                ->select($this->db->quoteName('code'))
                ->from($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('country'))
                ->where($this->db->quoteName('published') . ' = 1');

            $this->countries = array_fill_keys($this->db->setQuery($query)->loadColumn(), true);
        }

        return $this->countries;
    }

    /**
     * Countries with at least one published region.
     *
     * @return  array<string, bool>
     *
     * @since   1.0.0
     */
    private function loadCountriesWithRegions(): array
    {
        if ($this->countriesWithRegions === null) {
            $query = $this->db->createQuery()
                ->select('DISTINCT ' . $this->db->quoteName('country_code'))
                ->from($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('region'))
                ->where($this->db->quoteName('published') . ' = 1');

            $this->countriesWithRegions = array_fill_keys($this->db->setQuery($query)->loadColumn(), true);
        }

        return $this->countriesWithRegions;
    }
}
