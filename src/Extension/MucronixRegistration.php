<?php

/**
 * @package     Mucronix Registration
 * @subpackage  plg_user_mucronixregistration
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Mucronix\Plugin\User\MucronixRegistration\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Model\BeforeValidateDataEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Event\User\AfterDeleteEvent;
use Joomla\CMS\Event\User\AfterSaveEvent;
use Joomla\CMS\Event\User\BeforeSaveEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Mucronix\Plugin\User\MucronixRegistration\Service\RegionDirectory;

/**
 * Mucronix Registration: the username as the name on registration, country
 * and region of the user, the registration IP address.
 *
 * Stored in #__user_profiles under the keys mucronixregistration.country (ISO 3166-1
 * alpha-2), mucronixregistration.region (ISO 3166-2) and mucronixregistration.reg_ip.
 *
 * @since  1.0.0
 */
final class MucronixRegistration extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Prefix of the profile keys.
     *
     * @since  1.0.0
     */
    private const KEY_PREFIX = 'mucronixregistration.';

    /**
     * Name of the field group in the forms.
     *
     * @since  1.0.0
     */
    private const GROUP = 'mucronixregistration';

    /**
     * Values of the setting "Country and region in the profile on the site".
     *
     * @since  1.0.0
     */
    private const PROFILE_HIDE = 0;
    private const PROFILE_VIEW = 1;
    private const PROFILE_EDIT = 2;

    /**
     * Where a user is being saved.
     *
     * @since  1.0.0
     */
    private const SAVE_REGISTRATION = 'registration';
    private const SAVE_PROFILE      = 'profile';
    private const SAVE_ADMIN        = 'admin';

    /**
     * The countries and regions.
     *
     * @var    RegionDirectory|null
     * @since  1.0.0
     */
    private ?RegionDirectory $directory = null;

    /**
     * Returns the events this plugin listens to.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm'        => 'onContentPrepareForm',
            'onContentPrepareData'        => 'onContentPrepareData',
            'onContentBeforeValidateData' => 'onContentBeforeValidateData',
            'onUserBeforeSave'            => 'onUserBeforeSave',
            'onUserAfterSave'             => 'onUserAfterSave',
            'onUserAfterDelete'           => 'onUserAfterDelete',
            'onAjaxMucronixregistration'  => 'onAjaxMucronixregistration',
        ];
    }

    /**
     * Adds the fields to the registration, profile and user forms.
     *
     * @param   PrepareFormEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form = $event->getForm();
        $name = $form->getName();
        $app  = $this->getApplication();

        $site  = $app->isClient('site');
        $admin = $app->isClient('administrator');

        $supported = ($site && \in_array($name, ['com_users.registration', 'com_users.profile'], true))
            || ($admin && $name === 'com_users.user');

        if (!$supported) {
            return;
        }

        $this->loadLanguage();
        $form->loadFile(\dirname(__DIR__, 2) . '/forms/mucronixregistration.xml', false);

        if ($name === 'com_users.registration') {
            $this->prepareRegistrationForm($form);

            return;
        }

        if ($name === 'com_users.profile') {
            $this->prepareProfileForm($form, $event->getData());

            return;
        }

        // Administrator area: always visible and editable, nothing is required.
        // The read-only fields mean nothing for a user who is not saved yet.
        $data = $event->getData();
        $id   = (int) (\is_array($data) ? ($data['id'] ?? 0) : ($data->id ?? 0));

        if (!$id) {
            $form->removeField('country_code', self::GROUP);
            $form->removeField('reg_ip', self::GROUP);
        }

        $this->loadScript(false);
    }

    /**
     * Puts the stored values into the profile and user forms.
     *
     * @param   PrepareDataEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentPrepareData(PrepareDataEvent $event): void
    {
        if (!\in_array($event->getContext(), ['com_users.profile', 'com_users.user'], true)) {
            return;
        }

        $data = $event->getData();

        if (!\is_object($data) || empty($data->id)) {
            return;
        }

        $stored = $this->loadStored((int) $data->id);

        // A form shown again after an error keeps what was submitted.
        $values = isset($data->{self::GROUP}) ? (array) $data->{self::GROUP} : [
            'country' => $stored['country'] ?? '',
            'region'  => $stored['region'] ?? '',
        ];

        // Read-only values always come from the database.
        $values['country_code'] = $stored['country'] ?? '';
        $values['reg_ip']       = $stored['reg_ip'] ?? '';

        $data->{self::GROUP} = $values;
    }

    /**
     * Registration form: the name is the username, whatever came with the request.
     * Here and not in onUserBeforeSave: the data of that event is a copy.
     *
     * @param   BeforeValidateDataEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentBeforeValidateData(BeforeValidateDataEvent $event): void
    {
        if (
            $event->getForm()->getName() !== 'com_users.registration'
            || !$this->getApplication()->isClient('site')
            || !$this->params->get('hide_name', 1)
        ) {
            return;
        }

        $data = $event->getData();

        if (!\is_array($data)) {
            return;
        }

        $data['name'] = trim((string) ($data['username'] ?? ''));

        $event->updateData($data);
    }

    /**
     * Checks the country and region before the user is saved.
     *
     * @param   BeforeSaveEvent  $event  The event.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  With a message for the visitor when a value is not accepted.
     *
     * @since   1.0.0
     */
    public function onUserBeforeSave(BeforeSaveEvent $event): void
    {
        $isNew   = $event->getIsNew();
        $context = $this->saveContext($isNew);
        $data    = $event->getData();

        if ($context === null || (!isset($data[self::GROUP]) && $context !== self::SAVE_REGISTRATION)) {
            return;
        }

        $this->loadLanguage();

        $old    = $event->getUser();
        $stored = $isNew ? [] : $this->loadStored((int) ($old['id'] ?? 0));

        $this->resolve((array) ($data[self::GROUP] ?? []), $stored, $context);
    }

    /**
     * Stores the country, region and registration IP address.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onUserAfterSave(AfterSaveEvent $event): void
    {
        $user   = $event->getUser();
        $isNew  = $event->getIsNew();
        $userId = (int) ($user['id'] ?? 0);

        if (!$event->getSavingResult() || !$userId) {
            return;
        }

        $context = $this->saveContext($isNew);

        if ($context === null || (!isset($user[self::GROUP]) && $context !== self::SAVE_REGISTRATION)) {
            return;
        }

        $stored = $isNew ? [] : $this->loadStored($userId);

        try {
            $values = $this->resolve((array) ($user[self::GROUP] ?? []), $stored, $context);
        } catch (\InvalidArgumentException) {
            // Refused in onUserBeforeSave already.
            return;
        }

        // Only at registration on the site: an administrator creating a user is not the user.
        if ($context === self::SAVE_REGISTRATION && $this->params->get('store_ip', 1)) {
            $ip = $this->getClientIp();

            if ($ip !== '') {
                $values['reg_ip'] = $ip;
            }
        }

        try {
            $this->store($userId, $values);
        } catch (\RuntimeException $e) {
            $this->getApplication()->enqueueMessage($e->getMessage(), 'error');
        }
    }

    /**
     * Removes the data of a deleted user.
     *
     * @param   AfterDeleteEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onUserAfterDelete(AfterDeleteEvent $event): void
    {
        $userId = (int) ($event->getUser()['id'] ?? 0);

        if (!$event->getDeletingResult() || !$userId) {
            return;
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->delete($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote(self::KEY_PREFIX . '%'))
            ->bind(':userid', $userId, ParameterType::INTEGER);

        try {
            $db->setQuery($query)->execute();
        } catch (\RuntimeException $e) {
            $this->getApplication()->enqueueMessage($e->getMessage(), 'warning');
        }
    }

    /**
     * Regions of a country for the region list:
     * index.php?option=com_ajax&group=user&plugin=mucronixregistration&format=json&country=UA
     *
     * Public data, no token. Only regions of an enabled country with enabled regions.
     *
     * @param   AjaxEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAjaxMucronixregistration(AjaxEvent $event): void
    {
        $input   = $this->getApplication()->getInput();
        $country = RegionDirectory::normaliseCode($input->getString('country', ''));
        $tag     = $input->getString('language', '');

        if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag)) {
            $tag = $this->getApplication()->getLanguage()->getTag();
        }

        $list      = [];
        $directory = $this->directory();

        if (\strlen($country) === 2 && $directory->isCountryEnabled($country) && $directory->regionsEnabled($country)) {
            foreach ($directory->regionOptions($country, $tag) as $code => $name) {
                $list[] = ['code' => $code, 'name' => $name];
            }
        }

        $event->addResult($list);
    }

    /**
     * Shows the country name on the profile page instead of the code.
     * Called by com_users through HTMLHelper, see components/com_users/tmpl/profile/default_custom.php.
     *
     * @param   mixed  $value  The stored code.
     *
     * @return  string  Escaped HTML.
     *
     * @since   1.0.0
     */
    public function showCountry($value): string
    {
        return $this->showName($value);
    }

    /**
     * Shows the region name on the profile page instead of the code.
     *
     * @param   mixed  $value  The stored code.
     *
     * @return  string  Escaped HTML.
     *
     * @since   1.0.0
     */
    public function showRegion($value): string
    {
        return $this->showName($value);
    }

    /**
     * Registration form on the site.
     *
     * @param   Form  $form  The form.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function prepareRegistrationForm(Form $form): void
    {
        if ($this->params->get('hide_name', 1)) {
            // Hidden, not removed: the name is still part of the form, and
            // onContentBeforeValidateData fills it. No filter="unset" here:
            // it would wipe the value.
            $form->setFieldAttribute('name', 'type', 'hidden');
            $form->setFieldAttribute('name', 'required', 'false');
        }

        $this->removeAdminFields($form);
        $form->setFieldAttribute('country', 'required', $this->params->get('country_required', 1) ? 'true' : 'false', self::GROUP);

        $this->loadScript(true);
    }

    /**
     * Profile form on the site: the same form serves the profile page and its edit page.
     *
     * @param   Form          $form  The form.
     * @param   object|array  $data  The form data, with the stored values already in it.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function prepareProfileForm(Form $form, object|array $data): void
    {
        $mode    = (int) $this->params->get('profile_site', self::PROFILE_EDIT);
        $editing = $this->isProfileEditing();

        // Without the fields the submitted values are dropped by the form filter,
        // so a value sent by hand cannot reach the profile.
        if ($mode === self::PROFILE_HIDE || ($mode === self::PROFILE_VIEW && $editing)) {
            $form->removeGroup(self::GROUP);

            return;
        }

        $this->removeAdminFields($form);

        if ($editing) {
            $form->setFieldAttribute('country', 'required', $this->params->get('country_required', 1) ? 'true' : 'false', self::GROUP);
            $this->loadScript(true);

            return;
        }

        // An empty region is not shown at all: most countries have none,
        // and the core text for an empty value reads "Website default".
        $values = (array) ((\is_array($data) ? ($data[self::GROUP] ?? []) : ($data->{self::GROUP} ?? [])));

        if ((string) ($values['region'] ?? '') === '') {
            $form->removeField('region', self::GROUP);
        }

        // The profile page looks for a function users.<field id> before printing
        // the raw value. The core profile plugin registers its functions the same way.
        $functions = [
            'users.jform_' . self::GROUP . '_country' => [$this, 'showCountry'],
            'users.jform_' . self::GROUP . '_region'  => [$this, 'showRegion'],
        ];

        foreach ($functions as $key => $function) {
            if (!HTMLHelper::isRegistered($key)) {
                HTMLHelper::register($key, $function);
            }
        }
    }

    /**
     * Whether the profile form is used for editing or saving, not for the profile page.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function isProfileEditing(): bool
    {
        $input = $this->getApplication()->getInput();

        // getCmd() gives null, not an empty string, when there is no task.
        return $input->getCmd('layout', '') === 'edit' || $input->getCmd('task', '') !== '';
    }

    /**
     * Removes the fields that only the administrator area shows.
     *
     * @param   Form  $form  The form.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function removeAdminFields(Form $form): void
    {
        $form->removeField('country_code', self::GROUP);
        $form->removeField('reg_ip', self::GROUP);
    }

    /**
     * Adds the script that loads the regions of the chosen country.
     *
     * @param   boolean  $regionRequired  Whether a region is required when the country has them.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function loadScript(bool $regionRequired): void
    {
        $app      = $this->getApplication();
        $document = $app->getDocument();

        if (!$document instanceof HtmlDocument) {
            return;
        }

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_user_mucronixregistration');
        $wa->useScript('plg_user_mucronixregistration.regions');

        $document->addScriptOptions('plg_user_mucronixregistration', [
            'url'      => Uri::base(true) . '/index.php?option=com_ajax&group=user&plugin=mucronixregistration&format=json'
                . '&language=' . rawurlencode($app->getLanguage()->getTag()),
            'required' => $regionRequired,
        ]);
    }

    /**
     * Where the user is being saved, or null when the plugin keeps out of it.
     *
     * @param   boolean  $isNew  Whether the user is new.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function saveContext(bool $isNew): ?string
    {
        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            return self::SAVE_ADMIN;
        }

        if (!$app->isClient('site')) {
            return null;
        }

        $input  = $app->getInput();
        $option = $input->getCmd('option');

        if ($option !== 'com_users') {
            return null;
        }

        // Activation, password reset and other extensions saving users are not touched.
        if ($isNew) {
            return $this->isTask('registration', 'register') ? self::SAVE_REGISTRATION : null;
        }

        if ($this->isTask('profile', 'save') && (int) $this->params->get('profile_site', self::PROFILE_EDIT) === self::PROFILE_EDIT) {
            return self::SAVE_PROFILE;
        }

        return null;
    }

    /**
     * Whether the request runs controller.task of com_users. The component dispatcher
     * splits task=registration.register into controller=registration and task=register
     * before the controller runs (Joomla\CMS\Dispatcher\ComponentDispatcher::dispatch),
     * so both forms are accepted.
     *
     * @param   string  $controller  Controller name.
     * @param   string  $task        Task name.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function isTask(string $controller, string $task): bool
    {
        $input = $this->getApplication()->getInput();
        $value = $input->getCmd('task');

        return $value === $controller . '.' . $task
            || ($value === $task && $input->getCmd('controller') === $controller);
    }

    /**
     * Turns the submitted country and region into the values to store.
     *
     * A value saved earlier stays valid while it is not changed, even if the
     * administrator has disabled that country or its regions since.
     *
     * @param   array   $submitted  The submitted group: country, region.
     * @param   array   $stored     The stored values of the user.
     * @param   string  $context    Where the user is saved.
     *
     * @return  array{country: string, region: string}
     *
     * @throws  \InvalidArgumentException  When a value is not accepted.
     *
     * @since   1.0.0
     */
    private function resolve(array $submitted, array $stored, string $context): array
    {
        $directory = $this->directory();
        $required  = $context !== self::SAVE_ADMIN;

        $rawCountry = trim((string) (\is_scalar($submitted['country'] ?? null) ? $submitted['country'] : ''));
        $rawRegion  = trim((string) (\is_scalar($submitted['region'] ?? null) ? $submitted['region'] : ''));
        $country    = RegionDirectory::normaliseCode($rawCountry);
        $region     = RegionDirectory::normaliseCode($rawRegion);

        $storedCountry = (string) ($stored['country'] ?? '');
        $storedRegion  = (string) ($stored['region'] ?? '');

        if ($rawCountry === '') {
            if ($required && $this->params->get('country_required', 1)) {
                throw new \InvalidArgumentException(Text::_('PLG_USER_MUCRONIXREGISTRATION_ERROR_COUNTRY_REQUIRED'));
            }

            return ['country' => '', 'region' => ''];
        }

        $countryAccepted = \strlen($country) === 2
            && ($directory->isCountryEnabled($country) || ($country === $storedCountry && $directory->countryExists($country)));

        if (!$countryAccepted) {
            throw new \InvalidArgumentException(Text::_('PLG_USER_MUCRONIXREGISTRATION_ERROR_COUNTRY_INVALID'));
        }

        $regionsOffered = $directory->isCountryEnabled($country) && $directory->regionsEnabled($country);

        if ($rawRegion !== '') {
            if (!$directory->isRegionOf($region, $country)) {
                throw new \InvalidArgumentException(Text::_('PLG_USER_MUCRONIXREGISTRATION_ERROR_REGION_MISMATCH'));
            }

            $unchanged = $country === $storedCountry && $region === $storedRegion;

            if (!$regionsOffered && !$unchanged) {
                throw new \InvalidArgumentException(Text::_('PLG_USER_MUCRONIXREGISTRATION_ERROR_REGION_NOT_OFFERED'));
            }

            return ['country' => $country, 'region' => $region];
        }

        // No list is shown for a country whose regions are disabled now,
        // so an empty region there does not erase the saved one.
        if (!$regionsOffered && $country === $storedCountry && $storedRegion !== '') {
            return ['country' => $country, 'region' => $storedRegion];
        }

        if ($required && $regionsOffered) {
            throw new \InvalidArgumentException(Text::_('PLG_USER_MUCRONIXREGISTRATION_ERROR_REGION_REQUIRED'));
        }

        return ['country' => $country, 'region' => ''];
    }

    /**
     * Writes the values; an empty country or region removes its key.
     * Keys that are not passed, such as reg_ip on a profile edit, stay as they are.
     *
     * @param   integer  $userId  The user.
     * @param   array    $values  key => value, without the prefix.
     *
     * @return  void
     *
     * @throws  \RuntimeException  On a database error.
     *
     * @since   1.0.0
     */
    private function store(int $userId, array $values): void
    {
        $db   = $this->getDatabase();
        $keys = array_map(static fn(string $key): string => self::KEY_PREFIX . $key, array_keys($values));

        $query = $db->createQuery()
            ->delete($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->whereIn($db->quoteName('profile_key'), $keys, ParameterType::STRING)
            ->bind(':userid', $userId, ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        $query = $db->createQuery()
            ->insert($db->quoteName('#__user_profiles'))
            ->columns($db->quoteName(['user_id', 'profile_key', 'profile_value', 'ordering']));

        $ordering = 0;
        $rows     = 0;

        foreach (['country', 'region', 'reg_ip'] as $key) {
            $ordering++;

            if (($values[$key] ?? '') === '') {
                continue;
            }

            $query->values(implode(',', [
                $userId,
                $db->quote(self::KEY_PREFIX . $key),
                $db->quote((string) $values[$key]),
                $ordering,
            ]));
            $rows++;
        }

        if ($rows) {
            $db->setQuery($query)->execute();
        }
    }

    /**
     * Stored values of a user, without the key prefix.
     *
     * @param   integer  $userId  The user.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    private function loadStored(int $userId): array
    {
        if (!$userId) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['profile_key', 'profile_value']))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote(self::KEY_PREFIX . '%'))
            ->order($db->quoteName('ordering'))
            ->bind(':userid', $userId, ParameterType::INTEGER);

        try {
            $rows = $db->setQuery($query)->loadAssocList('profile_key', 'profile_value');
        } catch (\RuntimeException) {
            return [];
        }

        $stored = [];

        foreach ($rows as $key => $value) {
            $stored[substr($key, \strlen(self::KEY_PREFIX))] = (string) $value;
        }

        return $stored;
    }

    /**
     * Name of a stored code in the current language, escaped for the profile page.
     *
     * @param   mixed  $value  The code.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function showName($value): string
    {
        $code = RegionDirectory::normaliseCode($value);
        $name = $code !== '' ? $this->directory()->name($code, $this->getApplication()->getLanguage()->getTag()) : '';

        if ($name === '') {
            $name = \is_scalar($value) ? trim((string) $value) : '';
        }

        if ($name === '') {
            return Text::_('PLG_USER_MUCRONIXREGISTRATION_NOT_SPECIFIED');
        }

        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The visitor's IP address. Proxy headers are not read on purpose:
     * anyone can send them, only REMOTE_ADDR comes from the connection.
     *
     * @return  string  The address, or an empty string.
     *
     * @since   1.0.0
     */
    private function getClientIp(): string
    {
        $ip = $this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * The countries and regions with the settings of this plugin.
     *
     * @return  RegionDirectory
     *
     * @since   1.0.0
     */
    private function directory(): RegionDirectory
    {
        return $this->directory ??= new RegionDirectory($this->getDatabase(), $this->params);
    }
}
