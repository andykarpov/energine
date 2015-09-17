<?php
/**
 * @file
 * LoginForm
 *
 * It contains the definition to:
 * @code
class LoginForm;
 * @endcode
 *
 * @author dr.Pavka
 * @copyright Energine 2006
 *
 * @version 1.0.0
 */

namespace Energine\user\components;

use Energine\share\components\DataSet, Energine\share\gears\Primitive, Energine\share\gears\FieldDescription, Energine\share\gears\UserSession, Energine\share\gears\Field;

/**
 * Show login form.
 *
 * @code
class LoginForm;
 * @endcode
 */
class LoginForm extends DataSet implements SampleLoginForm {
    /**
     * @copydoc DataSet::__construct
     */
    public function __construct($name, array $params = null) {
        $params['state'] = E()->Document->user->isAuthenticated() ? 'showLogoutForm' : 'showLoginForm';
        parent::__construct($name, $params);
        $this->setTitle($this->translate('TXT_LOGIN_FORM'));
        $base = E()->SiteManager->getCurrentSite()->base;
        if (strpos($currDomain = E()->SiteManager->getCurrentSite()->host, Primitive::getConfigValue('site.domain')) === false) {
            $base = 'http://' . Primitive::getConfigValue('site.domain') . '/';
        }

        $this->setAction($base . 'auth.php' . ((isset($_SERVER['HTTP_REFERER'])) ? '' : '?return=' . (($return = $this->getParam('successAction')) ? $return : E()->Request->getURI())), true);
    }

    /**
     * @copydoc DataSet::defineParams
     */
    // Добавлен successAction - УРЛ на который происходит переадресация в случае успеха
    protected function defineParams() {
        return array_merge(
            parent::defineParams(),
            array(
                'successAction' => false
            )
        );
    }

    /**
     * Show login form.
     */
    public function showLoginForm() {
        $this->prepare();
        if (isset($_COOKIE[UserSession::FAILED_LOGIN_COOKIE_NAME])) {
            $messageField = new FieldDescription('message');
            $messageField->setType(FieldDescription::FIELD_TYPE_STRING);
            $this->getDataDescription()->addFieldDescription($messageField);
            $messageField->setRights(FieldDescription::FIELD_MODE_READ);

            $messageField = new Field('message');
            $messageField->addRowData($this->translate('ERR_BAD_LOGIN'));
            $this->getData()->addField($messageField);
            E()->Response->deleteCookie(UserSession::FAILED_LOGIN_COOKIE_NAME);
        }

        //Во избежание появления empty рекордсета
        $this->getData()->addField((new Field('username'))->setRowData(0, ''));
        //Если есть информация о авторизации через фейсбук
        foreach (array('auth.facebook', 'auth.vk') as $configSectionName) {
            if ($this->getToolbar()) {
                list($tbr) = array_values($this->getToolbar());
                if ($ctrl = $tbr->getControlByID($configSectionName)) $ctrl->disable();

                if ($ctrl && $this->getConfigValue($configSectionName)) {
                    if ($appID = $this->getConfigValue($configSectionName . '.appID')) {
                        $ctrl->setAttribute('appID', $appID);
                        $ctrl->enable();
                    }

                }
            }
        }
    }


    /**
     * Show logout form.
     */
    public function showLogoutForm() {
        //$request = E()->Request;
        //$this->setTitle($this->translate('TXT_LOGOUT'));
        $this->addTranslation('TXT_USER_GREETING', 'TXT_USER_NAME', 'TXT_ROLE_TEXT');
        //$this->setAction(E()->SiteManager->getCurrentSite()->base, true);
        $this->prepare();
        /*foreach (E()->UserGroup->getUserGroups($this->document->user->getID()) as $roleID) {
            $tmp = E()->UserGroup->getInfo($roleID);
            $data[] = $tmp['group_name'];
        }

        $this->getData()->getFieldByName('role_name')->setData(implode(', ', $data));*/
    }

    /**
     * @copydoc DataSet::loadData
     */
    protected function loadData() {
        $result = false;
        switch ($this->getState()) {
            case 'showLogoutForm':
                foreach ($this->getDataDescription()->getFieldDescriptionList() as $fieldName) {
                    $result[] = array($fieldName => $this->document->user->getValue($fieldName));
                }
                break;
            default:
                $result = parent::loadData();
        }
        return $result;
    }
}

/**
 * Fake interface for login form.
 */
interface SampleLoginForm {
}