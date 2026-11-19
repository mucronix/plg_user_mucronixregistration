<?php

/**
 * @package     Mucronix Registration
 * @subpackage  plg_user_mucronixregistration
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Mucronix\Plugin\User\MucronixRegistration\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Mucronix\Plugin\User\MucronixRegistration\Service\RegionDirectory;

/**
 * Choice of countries in the plugin settings. All countries of the table,
 * regardless of the settings; with regions="true" only countries that have regions.
 *
 * @since  1.0.0
 */
class MucronixcountriesField extends ListField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.0.0
     */
    protected $type = 'Mucronixcountries';

    /**
     * Method to get the field options.
     *
     * @return  object[]  The field option objects.
     *
     * @since   1.0.0
     */
    protected function getOptions()
    {
        $options = [];

        try {
            $directory   = RegionDirectory::fromPlugin($this->getDatabase());
            $tag         = Factory::getApplication()->getLanguage()->getTag();
            $withRegions = (string) $this->element['regions'] === 'true';

            foreach ($directory->allCountries($tag, $withRegions) as $code => $name) {
                $options[] = HTMLHelper::_('select.option', $code, $name . ' (' . $code . ')');
            }
        } catch (\RuntimeException) {
            // No table yet: an empty list.
        }

        return array_merge($options, parent::getOptions());
    }
}
