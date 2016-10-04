<?php
	namespace DataTable;
	use \CHtml, \Yii;

	class CheckBoxList extends \CInputWidget
	{
        //placeholder constant to use as column
        const CHECKBOX_COLUMN = '{CHECKBOX_COLUMN}';
		public $checkBoxColumn;
		public $options;
		public $errorOptions;
		
		public $multiple = true;

		public function init() {
			parent::init();
			$checkboxClass = class_exists(\Befound\Widgets\CheckBoxColumn::CLASS) ? \Befound\Widgets\CheckBoxColumn::CLASS : \CCheckBoxColumn::CLASS;
			$this->checkBoxColumn = array_merge(array(
				'class' => $checkboxClass,
				'headerTemplate' => '{item}',
				'checked' => function($model, $row, $source) { 
					if(is_array($this->model->{$this->attribute}))
					{
						return in_array($model->{$source->name}, $this->model->{$this->attribute});
					}
					else
					{
						return $model->{$source->name} == $this->model->{$this->attribute};
					}
				}
			), $this->checkBoxColumn);

			if (isset($this->checkBoxColumn['header']))
			{
				$this->checkBoxColumn['headerTemplate'] = $this->checkBoxColumn['header'] . '&nbsp;&nbsp;' . $this->checkBoxColumn['headerTemplate'];
			}
			if ($this->multiple)
			{
				$this->options['selectableRows'] = 2;
			}
			else
			{
				$this->options['selectableRows'] = 1;
			}

			CHtml::resolveNameID($this->model, $this->attribute, $this->htmlOptions);
			
			if(substr($this->htmlOptions['name'],-2)!=='[]')
			{
				$this->htmlOptions['name'] .= '[]';
			}
			$this->checkBoxColumn['checkBoxHtmlOptions']['name'] = $this->htmlOptions['name'];
            //search for placeholder const in the columns
            $foundplaceholder = false;
            foreach( $this->options['columns'] as $key => $column){
                if($column == static::CHECKBOX_COLUMN){
                    $this->options['columns'][$key] = $this->checkBoxColumn;
                    $foundplaceholder = true;
                    break;
                }
            }
            //if not found, choose the last column
            if(!$foundplaceholder)
            {
                $this->options['columns'][] = $this->checkBoxColumn;
            }
			if (!isset($this->options['id']))
			{
				$this->options['id'] = $this->resolveNameID()[1];
			}
		}
		public function run()
		{
			$widget = $this->beginWidget(DataTable::CLASS, $this->options);
			$widget->run();
			Yii::app()->clientScript->registerScript($widget->id . 'type', new \CJavaScriptExpression("$('#{$widget->id}')[0].type = 'DataTableCheckBoxList';"));
		}
	}
?>