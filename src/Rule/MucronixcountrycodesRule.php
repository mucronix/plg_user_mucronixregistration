<?php

/**
 * @package     Mucronix Registration
 * @subpackage  plg_user_mucronixregistration
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Mucronix\Plugin\User\MucronixRegistration\Rule;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Registry\Registry;
use Mucronix\Plugin\User\MucronixRegistration\Service\RegionDirectory;

/**
 * Checks "Countries at the top of the list": country codes separated by commas,
 * every one of them a country of the table. Unknown codes stop the saving
 * with their list, so a typo does not go unnoticed.
 *
 * @since  1.0.0
 */
class MucronixcountrycodesRule extends FormRule implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Method to test the value.
     *
     * @param   \SimpleXMLElement  $element  The SimpleXMLElement object representing the form field.
     * @param   mixed              $value    The form field value to validate.
     * @param   ?string            $group    The field name group control value.
     * @param   ?Registry          $input    An optional Registry object with the entire data set to validate against the entire form.
     * @param   ?Form              $form     The form object for which the field is being tested.
     *
     * @return  boolean  True if the value is valid.
     *
     * @throws  \UnexpectedValueException  With the list of unknown codes.
     *
     * @since   1.0.0
     */
    public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        $codes = RegionDirectory::splitCodes((string) $value);

        if (!$codes) {
            return true;
        }

        $directory = RegionDirectory::fromPlugin($this->getDatabase());
        $unknown   = [];

        foreach ($codes as $code) {
            if (!preg_match('/^[A-Z]{2}$/', $code) || !$directory->countryExists($code)) {
                $unknown[] = $code;
            }
        }

        if ($unknown) {
            throw new \UnexpectedValueException(
                Text::sprintf('PLG_USER_MUCRONIXREGISTRATION_ERROR_TOP_COUNTRIES', htmlspecialchars(implode(', ', $unknown), ENT_QUOTES, 'UTF-8'))
            );
        }

        return true;
    }
}
