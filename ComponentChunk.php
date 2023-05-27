<?php namespace rogoss\yafip;

/** @property string $label the label as defined in the page/component file */
class ComponentChunk {

    /** @var ComponentChunk */
    public ComponentChunk $parent;

    /** @var ComponentChunk[] */
    public array  $components = [];

    /** @var string|null */
    public $data = null;

    /** @var Layout */
    public $layout = null;

    private $_sLabel = "";

    public function __construct($sLabel) {
        $this->_sLabel = $sLabel;
    }

    public function __set($sField, $mValue) {
        switch ($sField) {
            case "label":
                $this->_sLabel = $mValue;
        }
    }

    public function __get($sField) {
        switch ($sField) {
            case "label":
                return (empty($this->parent) ? '' : $this->parent->label . ".") . $this->_sLabel;

            default:
                if (isset($this->$sField)) return $this->$sField;
        }
    }

    public function isDataChunk() {
        return !empty($this->data);
    }
}
