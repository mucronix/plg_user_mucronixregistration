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
 * Region list. It is built on the server for the country already in the form
 * (a saved profile, or the form shown again after an error), so it works
 * without JavaScript; the script reloads it when the country changes.
 *
 * No validate="options" here: the list depends on the country and on the
 * request, and the plugin checks the region against the country on saving.
 *
 * @since  1.0.0
 */
class MucronixregionField extends ListField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.0.0
     */
    protected $type = 'Mucronixregion';

    /**
     * Method to get the field options.
     *
     * @return  object[]  The field option objects.
     *
     * @since   1.0.0
     */
    protected function getOptions()
    {
        $options = [HTMLHelper::_('select.option', '', Text::_('PLG_USER_MUCRONIXREGISTRATION_SELECT_REGION'))];
        $country = $this->form ? RegionDirectory::normaliseCode($this->form->getValue('country', $this->group)) : '';

        if ($country === '') {
            return array_merge($options, parent::getOptions());
        }

        try {
            $directory = RegionDirectory::fromPlugin($this->getDatabase());
            $tag       = Factory::getApplication()->getLanguage()->getTag();
            $keep      = RegionDirectory::normaliseCode($this->value);

            foreach ($directory->regionOptions($country, $tag, $keep) as $code => $name) {
                $options[] = HTMLHelper::_('select.option', $code, $name);
            }
        } catch (\RuntimeException) {
            // No table: the form still renders, saving reports the problem.
        }

        return array_merge($options, parent::getOptions());
    }
}
