<?php
/*
 * Token LDAP Authentication plugin for LimeSurvey
 * Copyright (C) 2016 Daniel GL Gimeno Boal  <daniel.gimeno@gmail.com>
 * License: GNU/GPL License v2 http://www.gnu.org/licenses/gpl-2.0.html
 * A plugin of LimeSurvey, a free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

class AuthLdapToken extends ls\pluginmanager\AuthPluginBase
{
    protected $storage = 'DbStorage';

    protected $ssp = null;

    static protected $description = 'LDAP Token authentication plugin';
    static protected $name = 'Ldap Token Authentication';


    protected $autoCreate = false;

    protected $settings = array(

        'server' => array(
            'type' => 'string',
            'label' => 'Ldap server',
            'help' => 'e.g. ldap://ldap.example.com or ldaps://ldap.example.com'
            ),
        'ldapport' => array(
            'type' => 'string',
            'label' => 'Port number',
            'help' => 'Default when omitted is 389',
            ),
        'ldapversion' => array(
            'type' => 'select',
            'label' => 'LDAP version',
            'options' => array('2' => 'LDAPv2', '3'  => 'LDAPv3'),
            'default' => '2',
            'submitonchange'=> true
            ),
        'ldapoptreferrals' => array(
            'type' => 'boolean',
            'label' => 'Select true if referrals must be followed (use false for ActiveDirectory)',
            'default' => '0'
            ),
        'ldaptls' => array(
            'type' => 'boolean',
            'help' => 'Check to enable Start-TLS encryption, when using LDAPv3',
            'label' => 'Enable Start-TLS',
            'default' => '0'
            ),
        'ldapmode' => array(
            'type' => 'select',
            'label' => 'Select how to perform authentication.',
            'options' => array("simplebind" => "Simple bind", "searchandbind" => "Search and bind"),
            'default' => "simplebind",
            'submitonchange'=> true
            ),
        'userprefix' => array(
            'type' => 'string',
            'label' => 'Username prefix',
            'help' => 'e.g. cn= or uid=',
            ),
        'domainsuffix' => array(
                'type' => 'string',
                'label' => 'Username suffix',
                'help' => 'e.g. @mydomain.com or remaining part of ldap query',
                ),
        'searchuserattribute' => array(
                'type' => 'string',
                'label' => 'Attribute to compare to the given login can be uid, cn, mail, ...'
                ),
        'usersearchbase' => array(
                'type' => 'string',
                'label' => 'Base DN for the user search operation'
                ),
        'extrauserfilter' => array(
                'type' => 'string',
                'label' => 'Optional extra LDAP filter to be ANDed to the basic (searchuserattribute=username) filter. Don\'t forget the outmost enclosing parentheses'
                ),
        'binddn' => array(
                'type' => 'string',
                'label' => 'Optional DN of the LDAP account used to search for the end-user\'s DN. An anonymous bind is performed if empty.'
                ),
        'bindpwd' => array(
                'type' => 'password',
                'label' => 'Password of the LDAP account used to search for the end-user\'s DN if previoulsy set.'
                ),
        'mailattribute' => array(
                'type' => 'string',
                'label' => 'LDAP attribute of email address'
                ),
        'fullnameattribute' => array(
                'type' => 'string',
                'label' => 'LDAP attribute of full name'
                ),
        'is_default' => array(
                'type' => 'checkbox',
                'label' => 'Check to make default authentication method'
                ),
        'autocreate' => array(
                'type' => 'checkbox',
                'label' => 'Automatically create participant if it exists in LDAP server'
                ),
        'validFrom' => array(
                'type' => 'string',
                'label' => 'Date from which is valid'
                ),
        'validUntil' => array(
                'type' => 'string',
                'label' => 'date until which is valid'
                ),
    );


    public function __construct(PluginManager $manager, $id) {
        parent::__construct($manager, $id);
      //  $this->subscribe('newLoginForm');
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('beforeSurveySettings');
    //    $this->subscribe('newSurveySettings');
    }


    /**
    * init function
    *
    * @return mixed
    */
    function __init(){
        $bUseLdapTokenAuth=$this->get('bUseLdapTokenAuth');
        if(!is_null($bUseLdapTokenAuth))
            $this->bUseLdapTokenAuth=$bUseLdapTokenAuth;
        else
            $this->bUseLdapTokenAuth=false;
    }

    public function beforeSurveySettings()
    {
        $oEvent = $this->getEvent();
        $oEvent->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => array(
                'bUseLdapTokenAuth' => array(
                    'type' => 'select',
                    'options'=>array(
                        0=>'No',
                        1=>'Yes'
                    ),
                    'default'=>'0',//getGlobalSetting('LdapTokenAuth')['UseLdapTokenAuth_default'],//**********ESTO NO !!!!!!
                    'tab'=>'tokens', // Setting no used yet
                    'label' => 'habilitar autorizacion para participantes por LDAP',
                    'current' => $this->get('bUseLdapTokenAuth', 'Survey', $oEvent->get('survey'))
                ),
                ),
            )
        );
    }

    /**
    * beforeSurveyPage susbcription event
    *
    * @return mixed
    */
    public function beforeSurveyPage(){

        global $clienttoken, $token;

        $oEvent = $this->event;
        $iSurveyId = $oEvent->get('surveyId');
        $thissurvey = getSurveyInfo($iSurveyId);
        $request = $this->api->getRequest();

        // If Survey is anonymous finish
        if ($thissurvey['anonymized'] == "Y")  {
            return;
        }

        // If survey has not LDAP authentication ennabled Finish
        if ($this->get('bUseLdapTokenAuth', 'Survey', $iSurveyId) == false ) {
            $schemaFieldsName =Yii::app()->db->schema->getTable('{{tokens_' . $iSurveyId . '}}', true);
            if (is_null($schemaFieldsName)){
                // there's no token table support, return
                return;
            }
            //Is an old LDAP user, AVOID login
            if (in_array('usernameldap', array_keys($schemaFieldsName->columns))){
                if (isset($_SESSION['survey_'.$iSurveyId]['token']) || $request->getParam('token')!=''){
                  $udresult = Token::model($iSurveyId)->findAll("token = '$token'");
                    if (count($udresult)>0){
                        $oToken = $udresult[0];
                        if ($oToken->getAttribute('usernameldap')!=''){
                           Yii::app()->getController()->redirect(array('/'));
                        }
                    }

                }
            }
            return;
        }

        // Session pressent there is no need to perform any action
        if (isset($_SESSION['survey_'.$iSurveyId]['tokenname']) &&
        isset($_SESSION['survey_'.$iSurveyId]['tokenpassword'])
        ){
            $token = $_SESSION['survey_'.$iSurveyId]['tokenpassword'];
            return;
        }

        $tokenname = $request->getParam('tokenname',false)?$request->getParam('tokenname',false):isset($_SESSION['survey_'.$iSurveyId]['tokenname']);
        $tokenpassword = $request->getParam('tokenpassword')?$request->getParam('tokenpassword'):isset($_SESSION['survey_'.$iSurveyId]['tokenpassword']);
        $token = $request->getParam('token');

        if (($tokenname=='' || $tokenpassword=='')) {
            // collect login data
              unset($_SESSION['survey_'.$iSurveyId]['srid']);
               unset($_SESSION['survey_'.$iSurveyId]['step']);
            $this->renderHtml();
        }
        else {
            self::__init();
            $bUseLdapTokenAuth=$this->get('bUseLdapTokenAuth', 'Survey', $iSurveyId);
            /*if(is_null($bUseLdapTokenAuth))
                $bUseLdapTokenAuth=(!is_null ($this->bUseLdapTokenAuth))?$this->bUseLdapTokenAuth:null;*/
            if(!$bUseLdapTokenAuth)
                return;

            $oEvent->set('bUseLdapTokenAuth',$this->get('bUseLdapTokenAuth', 'Survey', $iSurveyId));

            if($iSurveyId && $tokenname)
            {
                // Get the survey model
                $oSurvey=Survey::model()->find("sid=:sid",array(':sid'=>$iSurveyId));

                if($oSurvey && $oSurvey->active=="Y" && $bUseLdapTokenAuth)
                {
                    /*-----Auth Core LDAP ---------*/
                    $ldapmode = $this->get('ldapmode');
                    $autoCreateFlag = false;

                    // Get configuration settings:
                    $ldapserver 		= $this->get('server');
                    $ldapport   		= $this->get('ldapport');
                    $suffix     		= $this->get('domainsuffix');
                    $prefix     		= $this->get('userprefix');
                    $searchuserattribute    = $this->get('searchuserattribute');
                    $extrauserfilter    	= $this->get('extrauserfilter');
                    $usersearchbase		= $this->get('usersearchbase');
                    $binddn     		= $this->get('binddn');
                    $bindpwd     		= $this->get('bindpwd');
                    $validFrom          = $this->get('validFrom');
                    $validUntil         = $this->get('validUntil');
                    $fullnameattribute  = $this->get('fullnameattribute');
                    $mailattribute      = $this->get('mailattribute');

                    // Try to connect
                    $ldapconn = $this->createConnection();
                    if (!is_resource($ldapconn))
                    {
                        $oEvent->set('success', false);
                        $oEvent->set('error', gT("Ldap connection error"));
                        return;
                    }

                    if (empty($ldapmode) || $ldapmode=='simplebind')
                    {
                        // in simple bind mode we know how to construct the userDN from the username
                        $ldapbind = @ldap_bind($ldapconn, $prefix . $tokenname . $suffix, $tokenpassword);
                    }
                    else
                    {
                        // in search and bind mode we first do a LDAP search from the username given
                        // to foind the userDN and then we procced to the bind operation
                        if (empty($binddn))
                        {
                            // There is no account defined to do the LDAP search,
                            // let's use anonymous bind instead
                            $ldapbindsearch = @ldap_bind($ldapconn);
                        }
                        else
                        {
                            // An account is defined to do the LDAP search, let's use it
                            $ldapbindsearch = @ldap_bind($ldapconn, $binddn, $bindpwd);
                        }
                        if (!$ldapbindsearch) {
                            $this->setAuthFailure(100, ldap_error($ldapconn));
                            $oEvent->set('error', gT("LDAP search has not given any results"));
                            ldap_close($ldapconn); // all done? close connection
                            return;
                        }
                        // Now prepare the search fitler
                        if ( $extrauserfilter != "")
                        {
                            $usersearchfilter = "(&($searchuserattribute=$tokenname)$extrauserfilter)";
                        }
                        else
                        {
                            $usersearchfilter = "($fullnameattribute=$tokenname)";
                        }

                        //$dnsearchres = ldap_search($ldapconn, $usersearchbase, $usersearchfilter, array($searchuserattribute));
                        $dnsearchres = ldap_search($ldapconn, $usersearchbase, $usersearchfilter, array('0'=>$fullnameattribute?$fullnameattribute:'name','1'=> $mailattribute?$mailattribute:'mail'));
                        //$dnsearchres = ldap_search($ldapconn, $usersearchbase, "cn=*", array('0'=>'name','1'=> 'mail'));
                        $rescount=ldap_count_entries($ldapconn,$dnsearchres);
                        if ($rescount == 1)
                        {
                            $tokenEntry=ldap_get_entries($ldapconn, $dnsearchres);
                            $tokenDn = $tokenEntry[0]["dn"]?$tokenEntry[0]["dn"]:'';
                            $tokenEmail = isset($tokenEntry[0]["mail"][0])?$tokenEntry[0]["mail"][0]:'';
                            $givenname = isset ($tokenEntry[0]["givenname"][0])?$tokenEntry[0]["givenname"][0]:'';
                        }
                        else
                        {
                            // if no entry or more than one entry returned
                            // then deny authentication
                            $oEvent->set('success', false);
                            $oEvent->set('error', gT("No Results found, incorrect Username and/or Password"));
                            ldap_close($ldapconn); // all done? close connection
                            ///Yii::app()->getController()->redirect(array('/'.$iSurveyId));
                            $this->renderHtml();
                            return;
                        }
                        $tokenDn = 'uid=' . $tokenname . "," . $usersearchbase;
                        // binding to ldap server with the userDN and privided credentials
                        $ldapbind = @ldap_bind($ldapconn, $tokenDn, $tokenpassword);
                    }

                    // verify user binding
                    if (!$ldapbind) {
                        $oEvent->set('success', FALSE);
                        $oEvent->set('error', gT("Incorrect Username and/or Password"));
                        ldap_close($ldapconn); // all done? close connection

                        $this->renderHtml();
                        return;
                    }

                    // Authentication was successful, now see if we have a Token or that we should create one
                    $bTokenExists = tableExists('{{tokens_' . $iSurveyId . '}}');
                    $udresult = array();
                    if (!$bTokenExists) //If no tokens table exists
                    {
                        $oEvent->set('success', false);
                        $oEvent->set('error', 'Survey not active');
                        return;
                    } else {
                      //$udresult = Token::model($iSurveyId)->findAll("firstname = '$tokenname' and token <> '' and token = '$tokenpassword'");
                        $schemaFieldsName =Yii::app()->db->schema->getTable('{{tokens_' . $iSurveyId . '}}', true);
                        if (in_array('firstname', array_keys($schemaFieldsName->columns))){
                            // We assume that the name is unique
                            //$udresult = Token::model($iSurveyId)->findAll("firstname = '$tokenname' AND ldaptokenpassword= '".sha1($tokenpassword)."'");
                            $udresult = Token::model($iSurveyId)->findAll("firstname = '$tokenname'");
                        }
                    }
                    if (count($udresult) == 0){
                        $newTokenPassword = \Yii::app()->securityManager->generateRandomString(10);
                        $schemaFieldsName =Yii::app()->db->schema->getTable('{{tokens_' . $iSurveyId . '}}', true);
                        LimeExpressionManager::SetDirtyFlag();
                        $tokenattributefieldnames = getAttributeFieldNames($iSurveyId);

//                        $i = 1;
//                        while (in_array('attribute_' . $i, $tokenattributefieldnames) !== false)
//                        {
//                            $i++;
//                        }
//                        $tokenattributefieldnames[] = 'attribute_' . $i;
                        if (!in_array('usernameldap', array_keys($schemaFieldsName->columns))){
                            Yii::app()->db->createCommand(Yii::app()->db->getSchema()->addColumn("{{tokens_".intval($iSurveyId)."}}", 'usernameldap' , 'string(255)'))->execute();
                        }

                        $cs =Yii::app()->db->schema->getTable('{{tokens_' . $iSurveyId . '}}', true);
                        LimeExpressionManager::SetDirtyFlag();

                        $aData = array(
                            'firstname' => $tokenname,
                            'lastname' => '',
                            'email' => $tokenEmail?$tokenEmail:'',
                            'emailstatus' => Yii::app()->request->getPost('emailstatus'),
                            'token' => $newTokenPassword,
                            'language' => sanitize_languagecode(Yii::app()->request->getPost('language')),
                            'sent' => 'N',
                            'remindersent' => 'N',
                            'completed' => 'N',
                            'usesleft' => 100,
                            'validfrom' => $validFrom,
                            'validuntil' => $validUntil,
                            'usernameldap' => $givenname
                        );

                        $token = Token::create($iSurveyId);
                        $token->setAttributes($aData, false);
                        $inresult = $token->save();
                    }
                    if (count($udresult) == 1){

                        $oToken = $udresult[0];
                        $newTokenPassword = $oToken->getAttribute('token');

                    }

                    $aData['success'] = true;

                    $oEvent->set('success', true);
                    $oEvent->set('tokenname', $tokenname);
                    $oEvent->set('tokenpassword', $newTokenPassword);

                    $clienttoken = $newTokenPassword;
                    $token =$newTokenPassword;
                    $_SESSION['survey_'.$iSurveyId]['token'] = $clienttoken;
                    $_SESSION['survey_'.$iSurveyId]['tokenname'] = $tokenname;
                    $_SESSION['survey_'.$iSurveyId]['tokenpassword'] = $newTokenPassword;
                    ldap_close($ldapconn); // Close connection

                }
            }
        }
    }

    /**
    * show login form
    *
    * @return mixed
    */
    public function renderHtml(){
            $cs = Yii::app()->clientScript;
            $cs->reset();
            \Yii::app()->bootstrap;
            echo '<html><head><title></title></head><body style="padding: 20px;">';
            Yii::app()->bootstrap->register();

            $cs->registerCssFile('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css');
            echo (CHtml::css("body{
                    color: #000000;
                }

                .Absolute-Center {
                    margin: auto;
                    position: absolute;
                    top: 0; left: 0; bottom: 0; right: 0;
                }
                .Absolute-Center.is-Responsive {
                    width: 50%;
                    height: 50%;
                    min-width: 300px;
                    max-width: 400px;
                padding: 40px;
                }
                #tokenmessage{
                    color:red;
                }
            .btn{ background-color: #2c3e50}
            .input-group-addon {background-color: #2c3e50}
                .errormessage {color:red}

                  "));

            echo (CHtml::tag('div', array('class' =>'Absolute-Center is-Responsive')));
            echo (CHtml::tag('div', array('class' =>'col-sm-12 col-md-12 col-md-offset-1')));
            echo (CHtml::tag('div', array(), CHtml::form(array("/survey/index","sid"=>$this->getEvent()->get('surveyId')), 'post', array('id'=>'tokenform', 'class'=>'form-horizontal col-sm-12', 'autocomplete'=>'off'))));
            echo (CHtml::tag('div', array('class' => 'form-group input-group'), "<span class='input-group-addon'><i class='glyphicon glyphicon-user'></i></span></label>      <input class='form-control' id='tokenname' required=''  type='text' name='tokenname' placeholder='".gT("Token name")."'/>"));
            echo (CHtml::tag('div', array('class' => 'form-group input-group'), "<span class='input-group-addon'><i class='glyphicon glyphicon-lock'></i></span></label><input class='form-control' type='password'  required=''  name='tokenpassword' placeholder='".gT("Token password")."'/>"));
            echo (CHtml::tag('div', array('class' => 'form-group'), "<input name='submit' id='submit' type='submit' size='40' maxlength='40' value='".gT("Start survey")."' class='btn btn-def btn-block' />"));
            if ($this->getEvent()->get('success') === false){
                echo (CHtml::tag('div', array('class' =>'alert alert-danger'),$this->getEvent()->get('error')));
            }
            echo '</body></html>';
            die("");
    }

    /**
    * Create LDAP connection
    *
    * @return mixed
    */
    private function createConnection()
    {
        // Get configuration settings:
        $ldapserver     = $this->get('server');
        $ldapport       = $this->get('ldapport');
        $ldapver        = $this->get('ldapversion');
        $ldaptls        = $this->get('ldaptls');
        $ldapoptreferrals = $this->get('ldapoptreferrals');

        if (empty($ldapport)) {
            $ldapport = 389;
        }

        // Try to connect
        $ldapconn = ldap_connect($ldapserver, (int) $ldapport);
        if (false == $ldapconn) {
            return array( "errorCode" => 1, "errorMessage" => gT('Error creating LDAP connection') );
        }

        // using LDAP version
        if ($ldapver === null)
        {
            // If the version hasn't been set, default = 2
            $ldapver = 2;
        }

        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, $ldapver);
        ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, $ldapoptreferrals);

        if (!empty($ldaptls) && $ldaptls == '1' && $ldapver == 3 && preg_match("/^ldaps:\/\//", $ldapserver) == 0 )
        {
            // starting TLS secure layer
            if(!ldap_start_tls($ldapconn))
            {
                ldap_close($ldapconn); // all done? close connection
                return array( "errorCode" => 100, 'errorMessage' => ldap_error($ldapconn) );
            }
        }

        return $ldapconn;
    }
    public function beforeLogin()
    {
        if ($this->get('is_default', null, null, false) == true) {
            // This is configured to be the default login method
            $this->getEvent()->set('default', get_class($this));
        }
    }

    public function newSurveySettings()
    {
        $event = $this->getEvent();
        foreach ($event->get('settings') as $name => $value)
        {
            $this->set($name, $value, 'Survey', $event->get('survey'));
        }
    }


}