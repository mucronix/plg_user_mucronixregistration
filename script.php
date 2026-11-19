<?php

/**
 * @package     Mucronix Registration
 * @subpackage  plg_user_mucronixregistration
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Installer\InstallerScriptTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The installer script service provider of the plugin.
 *
 * @since  1.0.0
 */
return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            function (Container $container) {
                return new class ($container->get(DatabaseInterface::class)) implements InstallerScriptInterface {
                    use InstallerScriptTrait {
                        postflight as private traitPostflight;
                    }

                    /**
                     * The database driver
                     *
                     * @var    DatabaseInterface
                     * @since  1.0.0
                     */
                    private DatabaseInterface $db;

                    /**
                     * Constructor
                     *
                     * @param   DatabaseInterface  $db  The database driver
                     *
                     * @since   1.0.0
                     */
                    public function __construct(DatabaseInterface $db)
                    {
                        $this->db            = $db;
                        $this->minimumPhp    = '8.3.0';
                        $this->minimumJoomla = '6.0.0';
                    }

                    /**
                     * After the first installation: a link to the plugin settings.
                     * The plugin is not enabled here; the site owner does it there.
                     *
                     * @param   string            $type     The type of change
                     * @param   InstallerAdapter  $adapter  The adapter calling this method
                     *
                     * @return  boolean  True on success
                     *
                     * @since   1.0.0
                     */
                    public function postflight(string $type, InstallerAdapter $adapter): bool
                    {
                        if ($type === 'install' || $type === 'discover_install') {
                            $query = $this->db->createQuery()
                                ->select($this->db->quoteName('extension_id'))
                                ->from($this->db->quoteName('#__extensions'))
                                ->where(
                                    [
                                        $this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'),
                                        $this->db->quoteName('folder') . ' = ' . $this->db->quote('user'),
                                        $this->db->quoteName('element') . ' = ' . $this->db->quote('mucronixregistration'),
                                    ]
                                );

                            $id = (int) $this->db->setQuery($query)->loadResult();

                            if ($id) {
                                $link = Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $id);

                                Factory::getApplication()->enqueueMessage(
                                    Text::sprintf('PLG_USER_MUCRONIXREGISTRATION_INSTALL_MESSAGE', $link),
                                    'message'
                                );
                            }
                        }

                        return $this->traitPostflight($type, $adapter);
                    }
                };
            }
        );
    }
};
