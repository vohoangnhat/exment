<?php

namespace Exceedone\Exment\DataItems\Form;

abstract class FormBase
{
    protected $custom_table;
    protected $custom_form;
    protected $id;
    protected $custom_value;

    /**
     * If true, all disabled tools button
     *
     * @var boolean
     */
    protected $disableToolsButton = false;

    /**
     * If true, disabled saving button
     *
     * @var boolean
     */
    protected $disableSavingButton = false;
    
    /**
     * If true, disableSavedRedirectCheck
     *
     * @var boolean
     */
    protected $disableSavedRedirectCheck = false;
    

    public static function getItem(...$args)
    {
        list($custom_table, $custom_form) = $args + [null, null];

        return new static($custom_table, $custom_form);
    }

    public function id($id = null)
    {
        $this->id = $id;
        if (!is_nullorempty($id)) {
            $this->custom_value = $this->custom_table->getValueModel($id);
        }

        return $this;
    }


    /**
     * If true, all disable tools button
     *
     * @return $this
     */
    public function disableToolsButton(){
        $this->disableToolsButton = true;

        return $this;
    }

    /**
     * If true, disable saving button
     *
     * @return $this
     */
    public function disableSavingButton(){
        $this->disableSavingButton = true;

        return $this;
    }


    /**
     * If true, disableSavedRedirectCheck
     *
     * @return $this
     */
    public function disableSavedRedirectCheck(){
        $this->disableSavedRedirectCheck = true;

        return $this;
    }


    abstract public function form();
}
