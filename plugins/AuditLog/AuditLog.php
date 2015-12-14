<?php
    class AuditLog extends \ls\pluginmanager\PluginBase {

        protected $storage = 'DbStorage';    
        static protected $description = 'Core: Create an audit log of changes';
        static protected $name = 'auditlog';

        protected $settings = array(
 			'AuditLog_Log_Enable' => array(
				'type' => 'checkbox',
				'label' => 'Enable AuditLog',
			),
			'AuditLog_Log_UserSave' => array(
				'type' => 'checkbox',
				'label' => 'Log if a user was modified or created',
			),
			'AuditLog_Log_UserLogin' => array(
				'type' => 'checkbox',
				'label' => 'Log if a user is logged successfully',
			),		
			'AuditLog_Log_UserLogout' => array(
				'type' => 'checkbox',
				'label' => 'Log if user has logout',
			),		
			'AuditLog_Log_UserFailedLoginAttempt' => array(
				'type' => 'checkbox',
				'label' => 'Log if a user login has failed',
			),			
			'AuditLog_Log_UserDelete' => array(
				'type' => 'checkbox',
				'label' => 'Log if a user was deleted',
			),
			'AuditLog_Log_ParticipantSave' => array(
				'type' => 'checkbox',
				'label' => 'Log if a participant was modified or created',
			),
			'AuditLog_Log_ParticipantDelete' => array(
				'type' => 'checkbox',
				'label' => 'Log if a participant was deleted',
			),
			'AuditLog_Log_UserPermissionsChanged' => array(
				'type' => 'checkbox',
				'label' => 'Log if a user participant was deleted',
			),
		);

				
		/**
		* Get default values from config-defaults
		* @return
		*/
		protected function AssignDefaultValues() {
			$this->settings['AuditLog_Log_Enable']['default']  = getGlobalSetting('auditLog')['AuditLog_Log_Enable'];
		}

		public function init() {
				$this->subscribe('beforeSurveySettings');
				$this->subscribe('newSurveySettings');
				$this->subscribe('beforeActivate');
				$this->subscribe('beforeUserSave');
				$this->subscribe('beforeUserDelete');
				$this->subscribe('beforePermissionSetSave'); 
				$this->subscribe('beforeParticipantSave'); 
				$this->subscribe('beforeParticipantDelete'); 
				$this->subscribe('beforeLogout');
				$this->subscribe('afterSuccessfulLogin');
				$this->subscribe('afterFailedLoginAttempt');
				$this->AssignDefaultValues();
		}
		
		/**
		* check for setting for a single operation event, login user, save or delete
		* @return boolean
		*/
		private function checkSetting($settingName) {
			$pluginsettings = $this->getPluginSettings(true);
			// Logging will done if setted to true
			return $pluginsettings[$settingName]['current'] == 1;
		}		
		
		
		/**
		* User logout to the audit log
		* @return unknown_type
		*/
		public function beforeLogout()
		{
			if (!$this->checkSetting('AuditLog_Log_UserLogout')) {
				return;
			}
			
			$oUser = $this->api->getCurrentUser();
			if ($oUser != false)
			{
				$iUserID = $oUser->uid;
				$oAutoLog = $this->api->newModel($this, 'log');
				$oAutoLog->uid=$iUserID;
				$oAutoLog->entity='user';
				$oAutoLog->entityid=$iUserID;
				$oAutoLog->action='beforeLogout';
				$oAutoLog->save();
			}
		}

		/**
		* Successfull login to the audit log
		* @return unknown_type
		*/
		public function afterSuccessfulLogin()
		{
			if (!$this->checkSetting('AuditLog_Log_UserLogin')) {
				return;
			}

			$iUserID = $this->api->getCurrentUser()->uid;
			$oAutoLog = $this->api->newModel($this, 'log');
			$oAutoLog->uid = $iUserID;
			$oAutoLog->entity = 'user';
			$oAutoLog->entityid = $iUserID;
			$oAutoLog->action = 'afterSuccessfulLogin';
			$oAutoLog->save();
		}

		/**
		* Failed login attempt to the audit log
		* @return unknown_type
		*/
		public function afterFailedLoginAttempt()
		{
			if (!$this->checkSetting('AuditLog_Log_UserFailedLoginAttempt')) {
				return;
			}
			
			$event = $this->getEvent();
			$identity = $event->get('identity');
			$oAutoLog = $this->api->newModel($this, 'log');
			$oAutoLog->entity='user';
			$oAutoLog->action='afterFailedLoginAttempt';
			$aUsername['username'] = $identity->username;
			$oAutoLog->newvalues = json_encode($aUsername);
			$oAutoLog->save();
		}

        /**
        * Saves permissions changes to the audit log
        */
        public function beforePermissionSetSave()
        {
			
			if (!$this->checkSetting('AuditLog_Log_UserPermissionsChanged')) {
				return;
			}			
			
            $event = $this->getEvent();
            $aNewPermissions=$event->get('aNewPermissions');
            $iSurveyID=$event->get('iSurveyID');
            $iUserID=$event->get('iUserID');
            $oCurrentUser=$this->api->getCurrentUser();
            $oOldPermission=$this->api->getPermissionSet($iUserID, $iSurveyID, 'survey');
            $sAction='update';   // Permissions are in general only updated (either you have a permission or you don't)

            if (count(array_diff_assoc_recursive($aNewPermissions,$oOldPermission)))
            {
                $oAutoLog = $this->api->newModel($this, 'log');
                $oAutoLog->uid=$oCurrentUser->uid;
                $oAutoLog->entity='permission';
                $oAutoLog->entityid=$iSurveyID;
                $oAutoLog->action=$sAction;
                $oAutoLog->oldvalues=json_encode(array_diff_assoc_recursive($oOldPermission,$aNewPermissions));
                $oAutoLog->newvalues=json_encode(array_diff_assoc_recursive($aNewPermissions,$oOldPermission));
                $oAutoLog->fields=implode(',',array_keys(array_diff_assoc_recursive($aNewPermissions,$oOldPermission)));
                $oAutoLog->save();
            }
        }
        
        /**
        * Function catches if a participant was modified or created
        * All data is saved - only the password hash is anonymized for security reasons
        */
        public function beforeParticipantSave()
        {
			
			if (!$this->checkSetting('AuditLog_Log_ParticipantSave')) {
				return;
			}			
			
            $oNewParticipant=$this->getEvent()->get('model');
            if ($oNewParticipant->isNewRecord)
            {
                return;
            }
            $oCurrentUser=$this->api->getCurrentUser();

            $aOldValues=$this->api->getParticipant($oNewParticipant->participant_id)->getAttributes();
            $aNewValues=$oNewParticipant->getAttributes();

            if (count(array_diff_assoc($aNewValues,$aOldValues)))
            {
                $oAutoLog = $this->api->newModel($this, 'log');
                $oAutoLog->uid=$oCurrentUser->uid;
                $oAutoLog->entity='participant';
                $oAutoLog->action='update';
                $oAutoLog->entityid=$aNewValues['participant_id'];
                $oAutoLog->oldvalues=json_encode(array_diff_assoc($aOldValues,$aNewValues));
                $oAutoLog->newvalues=json_encode(array_diff_assoc($aNewValues,$aOldValues));
                $oAutoLog->fields=implode(',',array_keys(array_diff_assoc($aNewValues,$aOldValues)));
                $oAutoLog->save();
            }
        }        
        
        /**
        * Function catches if a participant was modified or created
        * All data is saved - only the password hash is anonymized for security reasons
        */
        public function beforeParticipantDelete()
        {
			
			if (!$this->checkSetting('AuditLog_Log_ParticipantDelete')) {
				return;
			}			
			
            $oNewParticipant=$this->getEvent()->get('model');
            $oCurrentUser=$this->api->getCurrentUser();

            $aValues=$oNewParticipant->getAttributes();

            $oAutoLog = $this->api->newModel($this, 'log');
            $oAutoLog->uid=$oCurrentUser->uid;
            $oAutoLog->entity='participant';
            $oAutoLog->action='delete';
            $oAutoLog->entityid=$aValues['participant_id'];
            $oAutoLog->oldvalues=json_encode($aValues);
            $oAutoLog->fields=implode(',',array_keys($aValues));
            $oAutoLog->save();
        }            
        
        
        /**
        * Function catches if a user was modified or created
        * All data is saved - only the password hash is anonymized for security reasons
        */
        public function beforeUserSave()
        {
			
			if (!$this->checkSetting('AuditLog_Log_UserSave')) {
				return;
			}		

            $oUserData=$this->getEvent()->get('model');
            $oCurrentUser=$this->api->getCurrentUser();
            
            $aNewValues=$oUserData->getAttributes();
            if (!isset($oUserData->uid))
            {
                $sAction='create';
                $aOldValues=array();
                // Indicate the password has changed but assign fake hash
                $aNewValues['password']='*MASKED*PASSWORD*';
            }
            else
            {                
                $oOldUser=$this->api->getUser($oUserData->uid);
                $sAction='update';
                $aOldValues=$oOldUser->getAttributes();
                
                // Postgres delivers bytea fields as streams
                if (gettype($aOldValues['password'])=='resource')
                {
                    $aOldValues['password'] = stream_get_contents($aOldValues['password']);
                }
                // If the password has changed then indicate that it has changed but assign fake hashes
                if ($aNewValues['password']!=$aOldValues['password'])
                {
                    $aOldValues['password']='*MASKED*OLD*PASSWORD*';
                    $aNewValues['password']='*MASKED*NEW*PASSWORD*';
                };
            }
            
            if (count(array_diff_assoc($aNewValues,$aOldValues)))
            {
                $oAutoLog = $this->api->newModel($this, 'log');
                if ($oCurrentUser) {
                    $oAutoLog->uid=$oCurrentUser->uid;
                }
                else {
                    $oAutoLog->uid='Automatic creation';
                }
                $oAutoLog->entity='user';
                if ($sAction=='update') $oAutoLog->entityid=$oOldUser['uid'];
                $oAutoLog->action=$sAction;
                $oAutoLog->oldvalues=json_encode(array_diff_assoc($aOldValues,$aNewValues));
                $oAutoLog->newvalues=json_encode(array_diff_assoc($aNewValues,$aOldValues));
                $oAutoLog->fields=implode(',',array_keys(array_diff_assoc($aNewValues,$aOldValues)));
                $oAutoLog->save();
            }
        }
                                                            
        public function beforeUserDelete()
        {
			if (!$this->checkSetting('AuditLog_Log_UserDelete')) {
				return;
			}
			
            $oUserData=$this->getEvent()->get('model');
            $oCurrentUser=$this->api->getCurrentUser();
            $oOldUser=$this->api->getUser($oUserData->uid);
            if ($oOldUser)
            {
                $aOldValues=$oOldUser->getAttributes();
                unset($aOldValues['password']);
                $oAutoLog = $this->api->newModel($this, 'log');
                $oAutoLog->uid=$oCurrentUser->uid;
                $oAutoLog->entity='user';
                $oAutoLog->entityid=$oOldUser['uid'];
                $oAutoLog->action='delete';
                $oAutoLog->oldvalues=json_encode($aOldValues);
                $oAutoLog->fields=implode(',',array_keys($aOldValues));
                $oAutoLog->save();
            }
        }

        
                                                            
        public function beforeActivate()
        {
            if (!$this->api->tableExists($this, 'log'))
            {
                $this->api->createTable($this, 'log', array('id'=>'pk',
                    'created'=>'datetime',
                    'uid'=>'string',
                    'entity'=>'string',
                    'entityid'=>'string',
                    'action'=>'string',
                    'fields'=>'text',
                    'oldvalues'=>'text',
                    'newvalues'=>'text'));
            }
        }

        /**
        * This event is fired by the administration panel to gather extra settings
        * available for a survey.
        * The plugin should return setting meta data.
        */
        public function beforeSurveySettings()
        {
			$pluginsettings = $this->getPluginSettings(true);
			
            $event = $this->getEvent();
            $event->set("surveysettings.{$this->id}", array(
                'name' => get_class($this),
                'settings' => array(
                    'auditing' => array(
                        'type' => 'select',
                        'options'=>array(0=>'No',
                            1=>'Yes'),      
						'default' => $pluginsettings['AuditLog_Log_Enable']['current'],
                        'tab' => 'notification', // @todo: Setting no used yet
                        'category' => 'Auditing for person-related data', // @todo: Setting no used yet
                        'label' => 'Audit log for this survey',
                        'current' => $this->get('auditing', 'Survey', $event->get('survey'))
                )
                )
            ));
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
