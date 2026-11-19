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
use Joomla\CMS\Language\Text;
use Mucronix\Plugin\User\MucronixRegistration\Service\RegionDirectory;

/**
 * Country list of the registration and profile forms: the countries enabled
 * in the plugin settings, named in the current language.
 *
 * @since  1.0.0
 */
class MucronixcountryField extends ListField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.0.0
     */
    protected $type = 'Mucronixcountry';

    /**
     * Method to get the field options.
     *
     * @return  object[]  The field option objects.
     *
     * @since   1.0.0
     */
    protected function getOptions()
    {
        $options = [HTMLHelper::_('select.option', '', Text::_('PLG_USER_MUCRONIXREGISTRATION_SELECT_COUNTRY'))];

        try {
            $directory = RegionDirectory::fromPlugin($this->getDatabase());
            $tag       = Factory::getApplication()->getLanguage()->getTag();
            $keep      = RegionDirectory::normaliseCode($this->value);

            foreach ($directory->countryOptions($tag, $keep) as $code => $name) {
                $options[] = HTMLHelper::_('select.option', $code, $name);
            }
        } catch (\RuntimeException) {
            // No table: the form still renders, saving reports the problem.
        }

        return array_merge($options, parent::getOptions());
    }
}
