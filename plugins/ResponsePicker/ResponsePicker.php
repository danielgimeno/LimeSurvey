<?php
    require_once(__DIR__ . '/vendor/autoload.php');

    class ResponsePicker extends \ls\pluginmanager\PluginBase
    {

        static protected $description = 'This plugins allows a user to pick which response to work on if multiple candidate responses exist.';
        static protected $name = 'ResponsePicker';

        protected $storage = 'DbStorage';

        public function init()
        {
            $this->subscribe('beforeLoadResponse');
            // Provides survey specific settings.
            $this->subscribe('beforeSurveySettings');

            // Saves survey specific settings.
            $this->subscribe('newSurveySettings');

            $this->subscribe('newDirectRequest');
        }

        protected function viewResponse($response, $surveyId) {
            $out = '<html><title></title><body>';
            $rows = [];
            foreach ($response as $key => $value) {
                $rows[] = [
                    'question' => $key,
                    'answer' => $value
                ];
            }
            $out .= Yii::app()->controller->widget('zii.widgets.CDetailView', [
                'data' => $response

            ], true);
            $out .= CHtml::link("Back to list", $this->api->createUrl('survey/index', ['sid' => $surveyId, 'token' => $response['token'], 'lang' => 'en', 'newtest' => 'Y']));
            $out .= '</body></html>';
            Yii::app()->getClientScript()->render($out);
            echo $out;
        }
        public function newDirectRequest() {
            if ($this->event->get('target') == __CLASS__) {
                /** @var CHttpRequest $request */
                $request = $this->event->get('request');
                $surveyId = $request->getParam('surveyId');
                $responseId = $request->getParam('responseId');
                $token = $request->getParam('token');
                switch($this->event->get("function")) {
                    case 'delete':
                        /** @var \Response $response */
                        $response = Response::model($surveyId)->findByAttributes([
                            'id' => $responseId,
                            'token' => $token
                        ]);
                        if (isset($response)) {
                            $response->delete();
                        }
                        $request->redirect($request->urlReferrer);
                        break;
                    case 'read':
                        $response = $this->api->getResponse($surveyId, $responseId);
                        if (isset($response)) {
                            $this->viewResponse($response, $surveyId);
                        } else {
                            throw new \CHttpException(404, "Response not found.");
                        }
                        break;
                    case 'copy':
                        $this->redirectToCopy($surveyId, $responseId);
                        break;
                    default:
                        echo "Unknown action.";
                }
            }
        }


        /**
         * Create a copy of the given response and direct the user to that response.
         * @param $surveyId
         * @param $responseId
         */
        protected function redirectToCopy($surveyId, $responseId)
        {
            if (null === $response = \Response::model($surveyId)->findByPk($responseId)) {
                throw new \CHttpException(404, "Response not found.");
            }

            $response->id = null;
            $response->isNewRecord = true;
            $response->submitdate = null;
            $response->lastpage = 1;
            $response->save();

            $this->api->getRequest()->redirect($this->api->createUrl('survey/index', [
                'ResponsePicker' => $response->id,
                'sid' => $surveyId,
                'token' => $response->token
            ]));

        }
        public function beforeLoadResponse()
        {
            $surveyId = $this->event->get('surveyId');
            if ($this->get('enabled', 'Survey', $surveyId) == false) {
                return;
            }
            // Responses to choose from.
            $responses = $this->event->get('responses');
            /**
             * @var LSHttpRequest
             */
            $request = $this->api->getRequest();

            // Only handle get requests.
             if ($request->requestType == 'GET') // VALOR ORIGINAL
           // if ($request->requestType == 'POST')
            {
                $choice = $request->getParam('ResponsePicker');
                if (isset($choice)) {
                    if ($choice == 'new') {
                        $this->event->set('response', false);
                    } else {
                        foreach ($responses as $response) {
                            if ($response->id == $choice) {
                                $this->event->set('response', $response);
                                break;
                            }
                        }
                    }
                    /*
                     *  Save the choice in the session; if the survey has a
                     * welcome page, it is displayed and the response is "chosen"
                     * in the next request (which is a post)
                     */
                    $_SESSION['ResponsePicker'] = isset($response) ? $response->id : $choice;
                } else {
                    $this->renderOptions($request, $responses);
                }
            }
            else
            {
                if (isset($_SESSION['ResponsePicker']) || $request->getParam('ResponsePicker')!='')
                //if ($request->getParam('ResponsePicker')!='')
                {

                    $choice = isset($_SESSION['ResponsePicker'])?$_SESSION['ResponsePicker']:$request->getParam('ResponsePicker');
                    unset($_SESSION['ResponsePicker']);
                    if ($choice == 'new')
                    {
                        $this->event->set('response', false);
                    }
                    else
                    {
                        foreach ($responses as $response)
                        {
                            if ($response->id == $choice)
                            {

                                $this->event->set('response', $response);
                                break;
                            }
                        }
                    }
                }
                else {
                    $this->renderOptions($request, $responses);
                }

            }
        }

        public function beforeSurveySettings()
        {
            $event = $this->event;
            $settings = [
                'name' => get_class($this),
                'settings' => [
                    'enabled' => [
                        'type' => 'boolean',
                        'label' => 'Use response picker this survey: ',
                        'current' => $this->get('enabled', 'Survey', $event->get('survey'), 0)
                    ],
                    'update' => [
                        'type' => 'boolean',
                        'label' => 'Enable update button: ',
                        'current' => $this->get('update', 'Survey', $event->get('survey'), 1)
                    ],
                    'repeat' => [
                        'type' => 'boolean',
                        'label' => 'Enable repeat button: ',
                        'current' => $this->get('repeat', 'Survey', $event->get('survey'), 1)
                    ],
                    'view' => [
                        'type' => 'boolean',
                        'label' => 'Enable view button: ',
                        'current' => $this->get('view', 'Survey', $event->get('survey'), 1)
                    ],
                    'delete' => [
                        'type' => 'boolean',
                        'label' => 'Enable delete button: ',
                        'current' => $this->get('delete', 'Survey', $event->get('survey'), 1)
                    ],
                    'create' => [
                        'type' => 'boolean',
                        'label' => 'Enable create button: ',
                        'current' => $this->get('create', 'Survey', $event->get('survey'), 1)
                    ],

                    'columns' => [
                        'type' => 'text',
                        'label' => 'Show these columns (One question code per line):',
                        'current' => $this->get('columns', 'Survey', $event->get('survey'), "")
                    ],
                    'newheader' => [
                        'type' => 'string',
                        'label' => 'Header for new response button:',
                        'current' => $this->get('newheader', 'Survey', $event->get('survey'), "New response")
                    ]


                ],
            ];
            $event->set("surveysettings.{$this->id}", $settings);

        }
        protected function renderOptions($request, $responses)
        {
            $sid = $request->getParam('sid');
            $token  = $request->getParam('token');
            $lang = $request->getParam('lang');
            $newtest = $request->getParam('newtest');
            $tokenname = $request->getPost('tokenname');

            $tokenpassword = isset($_SESSION['survey_'.$sid]['tokenpassword'])?$_SESSION['survey_'.$sid]['tokenpassword']:''; //$request->getParam('tokenpassword');
            $tokenpassword = ($tokenpassword!='')?$tokenpassword:$token;
            $params = [
                'ResponsePicker' => 'new',
            ];
            if (isset($sid))
            {
                $params['sid'] = $sid;
            }
            if (isset($token))
            {
                $params['token'] = $token;
            }
            if (isset($lang))
            {
                $params['lang'] = $lang;
            }
            if (isset($newtest))
            {
                $params['newtest'] = $newtest;
            }
            /*if (isset($tokenname))
            {
                $params['tokennamedf'] = $tokenname;
            }*/
            if (isset($tokenpassword))
            {
                $params['token'] = $tokenpassword.  $token ;
            }


//            $result = [];
            foreach ($responses as $response)
            {
//                echo '<pre>'; var_dump(array_keys($response->attributes)); die();
                $result[] = [
//                    'series' => $response->uoid,
                    'data' => $this->api->getResponse($response->surveyId, $response->id),
                    'urls' => [
                        //'delete' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'delete', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token, 'tokenname'=> $tokenname, 'tokenpassword'=>$tokenpassword]),
                        'delete' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'delete', 'surveyId' => $response->surveyId,'ResponsePicker' => $response->id, 'responseId' => $response->id, 'token' => $response->token]),
                        'read' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'read', 'surveyId' => $response->surveyId, 'responseId' => $response->id, 'token' => $response->token, 'tokenname'=> $tokenname, 'tokenpassword'=>$tokenpassword]),
                        'update' => $this->api->createUrl('survey/index', array_merge($params, ['ResponsePicker' => $response->id, 'token' => $response->token])),
                        'copy' => $this->api->createUrl('plugins/direct', ['plugin' => __CLASS__, 'function' => 'copy', 'surveyId' => $response->surveyId, 'responseId' => $response->id]),

                    ],
                ];
            }
            $result[] = [
                'id' => 'new',
                'url' => $this->api->createUrl('survey/index', $params)
            ];
            $this->renderHtml($result, $sid);
        }

        protected function renderJson($result) {
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode($result, JSON_PRETTY_PRINT);
            die();
        }


        protected function renderHtml($result, $sid) {
            $new = array_pop($result);
            $columns = [];
            if (isset($result[0]['data'])) {
                foreach($result[0]['data'] as $key => $value) {
                    $columns[$key] = true;
                }
            }

            $template = [];
            foreach(['view', 'update', 'repeat', 'delete'] as $action) {
                if ($this->get($action, 'Survey', $sid, 1)) {
                    $template[] = '{' . $action . '}';
                }

            }
            if (!empty($template)) {
                $gridColumns['actions'] = [
                    'header' => 'Actions',
                    'htmlOptions' => [
                        'width' => '100px',
                    ],
                    'class' => \CButtonColumn::class,
                    'template' => implode(' ', $template),
                    'buttons' => [
                        'view' => [
                            'label' => '<span class="inputbuttons-square glyphicon glyphicon-eye-open text-success"></span>',
                            'options' => [
                                'title' => 'View data'
                            ],
                            'imageUrl' => false,
                            'url' => function ($data) {
                                return $data['urls']['read'];
                            }
                        ],
                        'update' => [
                            'label' => '<span class="inputbuttons-square glyphicon glyphicon-pencil text-success"></span>',
                            'imageUrl' => false,
                            'options' => [
                                'title' => 'Update data'
                            ],
                            'url' => function ($data) {
                                return $data['urls']['update'];
                            }
                        ],
                        'repeat' => [
                            'label' => '<span class="inputbuttons-square glyphicon glyphicon-plus-sign text-success"></span>',
                            'imageUrl' => false,
                            'options' => [
                                'title' => 'Create new response based on this one'
                            ],
                            'url' => function ($data) {
                                return $data['urls']['copy'];
                            }
                        ],
                        'delete' => [
                            'label' => '<span class="inputbuttons-square glyphicon glyphicon-trash text-success"></span>',
                            'imageUrl' => false,
                            'options' => [
                                'title' => 'Delete data'
                            ],

                            'url' => function ($data) {
                                return $data['urls']['delete'];
                            }
                        ]
                    ]
                ];
            }
            $gridColumns['id'] = [
                'name' => 'data.id',
                'header' => "Response id",
                'filter'=> false,
                'htmlOptions' => [
                    'width' => '100px'
                ]

            ];
            $gridColumns['submitdate'] = [
                'name' => 'data.submitdate',
                'header' => "Submit Date",
                'filter'=> 'text',
                'htmlOptions' => [
                    'width' => '200px'
                ]
            ];
            $series = [];
         /*   foreach($result as $row) {
                $id = $row['data']['UOID'];
                if (!isset($series[$id])) {
                    $series[$id] = $row['data']['id'];
                } else {
                    $series[$id] = max($series[$id], $row['data']['id']);
                }
            }

            foreach($result as &$row) {
                $id = $row['data']['UOID'];
                $row['final'] = ($series[$id] === $row['data']['id']) ? "True" : "False";
            }*/
            $gridColumns['final'] = [
                'name' => 'final',
                'header' => 'Last',
                'filter' => 'select-strict'
            ];
            $configuredColumns = explode("\r\n", $this->get('columns', 'Survey', $sid, ""));
            foreach($configuredColumns as $column) {
                if (strpos($column, ':') === false) {
                    $column .= ':none';
                }
                list($name, $filter) = explode(':', $column, 2);
                $question = Question::model()->findByAttributes([
                    'sid' => $sid,
                    'title' => $name
                ]);
                if (isset($question)) {
                    $answers = [];
                    foreach (Answer::model()->findAllByAttributes([
                        'qid' => $question->qid,
                    ]) as $answer) {
                        $answers[$answer->code] = $answer;
                    }

                    $gridColumns[$name] = [
                        'name' => "data.$name",
                        'header' => $question->question,
                        'filter' => ($filter == 'none') ? false : $filter,
                    ];
                    if (isset($answers) && !empty($answers)) {
                        $gridColumns[$name]['value'] = function ($row) use ($answers, $name) {
                            if (isset($answers[$row['data'][$name]])) {
                                return $answers[$row['data'][$name]]->answer;
                            } else {
                                return "No text found for: $name";
                            }
                        };

                    }

                }
            }


            foreach ($columns as $column => $dummy) {
                if (substr($column, 0, 4) == 'DISP') {
                    $gridColumns[$column] = [
                        'name' => "data.$column",
                        'header' => ucfirst($column),
                        'filter'=> 'select-strict'
                    ];
                }
            }
            /** @var \CClientScript $cs */
            $cs = Yii::app()->clientScript;
            $cs->reset();
            \Yii::app()->bootstrap;
            header('Content-Type: text/html; charset=utf-8');

            echo '<html><title></title><body style="padding: 20px;">';
            $header = $this->get('newheader', 'Survey', $sid, "New response");

            echo \CHtml::link($header, $new['url'], ['class' => 'btn']);
            Yii::app()->bootstrap->register();
            App()->getClientScript()->registerCssFile(App()->baseUrl . '/installer/css/fonts.css');
            \Yii::import('zii.widgets.grid.CGridView');
            \Yii::app()->params['bower-asset'] = \Yii::app()->assetManager->publish(__DIR__ . '/vendor/bower-asset');
            //$cs->registerCssFile('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css');
            $cs->registerCss('select', implode("\n", [
                'select { width: 100%; }',
                'input[type=text] { height: 30px;}',
                'label > select { width: auto; }',
                '.datatable-view { padding-top: 16px;}',
                '.dataTables_length { position: absolute; }'

            ]));

            echo Yii::app()->controller->widget(SamIT\Yii1\DataTables\DataTable::class, [
                'dataProvider' => new CArrayDataProvider($result, [
                    'keyField' => false
                ]),
                'pageSizeOptions' => [-1, 10, 25],
                'filter' => true,
                'columns' => $gridColumns

            ], true);
            echo '</body></html>';
            die();
        }
        public function newSurveySettings()
        {
            foreach ($this->event->get('settings') as $name => $value)
            {
                if ($name != 'count')
                {
                    $this->set($name, $value, 'Survey', $this->event->get('survey'));
                }
            }
        }
    }
?>
