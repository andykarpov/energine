<?php
/**
 * Содержит класс Form
 *
 * @package energine
 * @subpackage forms
 * @author d.pavka
 * @copyright d.pavka@gmail.com
 */

/**
 * Форма
 *
 * @package energine
 * @subpackage forms
 * @author d.pavka@gmail.com
 */
class Form extends DBDataSet
{
    /*
     * Form identifier
     */
    private $formID;
    /**
     * Конструктор класса
     *
     * @param string $name
     * @param string $module
     * @param array $params
     * @access public
     */
    public function __construct($name, $module, array $params = null){
        parent::__construct($name, $module, $params);
        if(!($this->formID = $this->getParam('id'))){
            $this->formID = simplifyDBResult(
                $this->dbh->selectRequest('SELECT form_id FROM frm_forms WHERE form_is_active = 1 ORDER BY RAND() LIMIT 1'),
                'form_id',
                true
            );
        }

        if(!$this->formID || !$this->dbh->tableExists($tableName = $this->getConfigValue('forms.database').'.'.FormConstructor::TABLE_PREFIX.$this->formID)){
            throw new SystemException('ERR_NO_FORM', SystemException::ERR_404, $this->getParam('id'));
        }
        $this->setTableName($tableName);
        $this->setType(self::COMPONENT_TYPE_FORM_ADD);
        $this->setAction('send');
        $this->addTranslation('TXT_ENTER_CAPTCHA');
    }
    protected function defineParams(){
        return array_merge(
            parent::defineParams(),
            array(
                'id' => false,
                'active' => true
            )
        );
    }

    /*
    * Викликаємо у випадку помилки з captcha
    */
    protected function failure($errorMessage, $data) {
        $this->config->setCurrentState('main');
        $this->prepare();
        $eFD = new FieldDescription('error_message');
        $eFD->setMode(FieldDescription::FIELD_MODE_READ);
        $eFD->setType(FieldDescription::FIELD_TYPE_CUSTOM);
        $this->getDataDescription()->addFieldDescription($eFD);
        $this->getData()->load(array(array_merge(array('error_message' => $errorMessage), $data)));
        $this->getDataDescription()->getFieldDescriptionByName('error_message')->removeProperty('title');
    }    
    
    protected function prepare() {
        parent::prepare();
        if (
            $this->document->getUser()->isAuthenticated()
            &&
            ($captcha =
                    $this->getDataDescription()->getFieldDescriptionByName('captcha'))
        ) {
            $this->getDataDescription()->removeFieldDescription($captcha);
        }
    }

    protected function createDataDescription(){
        $result = parent::createDataDescription();
        //Create captcha field for main state - when displaying form.
        if(!in_array($this->getState(), array('send', 'save', 'success'))){
            $fd = new FieldDescription('captcha');
            $fd->setType(FieldDescription::FIELD_TYPE_CAPTCHA);
            $result->addFieldDescription($fd);
        }
        return $result;
    }

    /**
     * Сохраняет данные
     *
     * @return mixed
     * @access protected
     */

    protected function saveData($data) {
        $result = false;
        //создаем объект описания данных
        $dataDescriptionObject = new DataDescription();

        //получаем описание полей для метода
        $configDataDescription =
                $this->config->getStateConfig($this->getPreviousState());
        //если в конфиге есть описание полей для метода - загружаем их
        if (isset($configDataDescription->fields)) {
            $dataDescriptionObject->loadXML($configDataDescription->fields);
        }

        //Создаем объект описания данных взятых из БД
        $DBDataDescription = new DataDescription();
        //Загружаем в него инфу о колонках
        $DBDataDescription->load($this->loadDataDescription());
        $this->setDataDescription($dataDescriptionObject->intersect($DBDataDescription));

        $dataObject = new Data();
        $dataObject->load($data);
        $this->setData($dataObject);

        //Создаем сейвер
        $this->saver = new Saver();
        //Устанавливаем его режим
        $this->saver->setMode(self::COMPONENT_TYPE_FORM_ADD);
        $this->saver->setDataDescription($this->getDataDescription());
        $this->saver->setData($this->getData());

        if ($this->saver->validate() === true) {
            $this->saver->setFilter($this->getFilter());
            $this->saver->save();
            $result = $this->saver->getResult();

        }
        else {
            //выдвигается пустой exception который перехватывается в методе save
            throw new FormException();
        }

        return $result;

    }

    protected function send() {
        $postTableName = str_replace('.','_',$this->getTableName());
        if(!isset($_POST[$postTableName])){
            E()->getResponse()->redirectToCurrentSection();
        }
        try {
            $data[$this->getTableName()] = $_POST[$postTableName];

            if (!$this->document->getUser()->isAuthenticated()) {
                $this->checkCaptcha();
            }
            if ($result = $this->saveData($data)) {
                $data = $data[$this->getTableName()];

            
//                $senderEmail = '';
//                if (isset($data['feed_email'])) {
//                    $senderEmail = $data['feed_email'];
//                } else {
//                    $data['feed_email'] =
//                            $this->translate('TXT_NO_EMAIL_ENTERED');
//                }

//                $this->dbh->modify(QAL::UPDATE, $this->getTableName(), array('feed_date' => date('Y-m-d H:i:s')), array($this->getPK() => $result));
//                if ($senderEmail) {
//                    $mailer = new Mail();
//                    $mailer->setFrom($this->getConfigValue('mail.from'))->
//                            setSubject($this->translate($this->getParam('userSubject')))->
//                            setText($this->translate($this->getParam('userBody')), $data)->
//                            addTo($senderEmail, $senderEmail)
//                            ->send();
//                }

                //Unset pk_id field, because we don't need it in body of message to send
                unset($data['pk_id']);
                foreach($data as $key=>$value){
                    $data[$key] = array('translation' => $this->translate('FIELD_FORM_'.$this->formID.'_'.$key), 'value' => $value);
                }

                try {
                    $mailer = new Mail();
                    //Get subject
                    $subject = simplifyDBResult(
                                    $this->dbh->select(
                                        'frm_forms_translation',
                                        array('form_name'),
                                        array('form_id' => $this->formID, 'lang_id' => E()->getLanguage()->getCurrent())),
                                    'form_name',
                                    true);
                    $subject = $this->translate('TXT_EMAIL_FROM_FORM').' '.$subject;

                    //Create text to send. The last one will contain: translations of variables and  variables.
                    $body = '';
                    foreach($data as $value){
                        $body .= $value['translation'].': '.$value['value'].'<br>';
                    }
                    $mailer->setFrom($this->getConfigValue('mail.from'))->
                            setSubject($subject)->
                            setText($body)->
                            addTo($this->getRecipientEmail())->send();
                }
                catch (Exception $e) {
                }
            }


            $this->prepare();

            if ($this->getParam('textBlock') && ($textBlock =
                    $this->document->componentManager->getBlockByName($this->getParam('textBlock')))) {
                $textBlock->disable();
            }

            $this->response->redirectToCurrentSection('success/');

        }
        catch (Exception $e) {
            $this->failure($e->getMessage(), $data[$this->getTableName()]);
        }
    }

    /*
     * Перевіряє капчу
     */
    protected function checkCaptcha() {
        require_once('core/kernel/recaptchalib.php');
        $privatekey = $this->getConfigValue('recaptcha.private');
        $resp = recaptcha_check_answer($privatekey,
            $_SERVER["REMOTE_ADDR"],
            $_POST["recaptcha_challenge_field"],
            $_POST["recaptcha_response_field"]);

        if (!$resp->is_valid) {
            throw new SystemException($this->translate('TXT_BAD_CAPTCHA'), SystemException::ERR_CRITICAL);
        }
    }    

    protected function success() {
        $this->setBuilder($this->createBuilder());

        $dataDescription = new DataDescription();
        $ddi = new FieldDescription('result');
        $ddi->setType(FieldDescription::FIELD_TYPE_TEXT);
        $ddi->setMode(FieldDescription::FIELD_MODE_READ);
        $ddi->removeProperty('title');
        $dataDescription->addFieldDescription($ddi);

        $data = new Data();
        $di = new Field('result');
        $di->setData($this->translate('TXT_FORM_SUCCESS_SEND'));
        $data->addField($di);

        $this->setDataDescription($dataDescription);
        $this->setData($data);
    }

    /**
     * Визначає адресу отримувача інформації з форми
     *
     * @return string
     * @access private
     */
    protected function getRecipientEmail($options = false) {
        $result = simplifyDBResult($this->dbh->select('frm_forms', array('form_email_adresses'), array('form_id' => $this->formID)),
                                   'form_email_adresses',
                                   true);
        return $result;
    }

}