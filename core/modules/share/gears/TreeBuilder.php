<?php
/**
 * @file
 * TreeBuilder.
 *
 * It contains the definition to:
 * @code
class TreeBuilder;
 * @endcode
 *
 * @author dr.Pavka
 * @copyright Energine 2006
 *
 * @version 1.0.0
 */
namespace Energine\share\gears;
/**
 * Builder of tree-like data.
 *
 * @code
class TreeBuilder;
 * @endcode
 *
 * Except of Data and DataDescription it contain Tree whereby the structure will be defined.
 */
class TreeBuilder extends Builder {
    /**
     * field name with key ID.
     * @var string $idFieldName
     */
    private $idFieldName = false;
    /**
     * Tree
     * @var TreeNodeList $tree
     */
    private $tree;

    /**
     * Set tree.
     *
     * @param TreeNodeList $tree New tree.
     */
    public function setTree(TreeNodeList $tree) {
        $this->tree = $tree;
    }

    /**
     * Get tree.
     *
     * @return TreeNodeList
     */
    public function getTree() {
        return $this->tree;
    }

    /**
     * @copydoc Builder::run
     *
     * @throws SystemException 'ERR_DEV_NO_TREE_IDENT'
     */
    protected function run() {
        foreach ($this->dataDescription as $fieldName => $fieldDescription) {
            if (!is_null($fieldDescription->getPropertyValue('key'))) {
                $this->idFieldName = $fieldName;
            }
        }
        if (!$this->idFieldName) {
            throw new SystemException('ERR_DEV_NO_TREE_IDENT', SystemException::ERR_DEVELOPER, [$this->idFieldName]);
        }
        if (!$this->data->isEmpty()) {
            $this->treeBuild($this->tree, $this->getResult());
        }
    }

    /**
     * Build tree-like XML.
     *
     * @param TreeNodeList $tree Tree.
     * @param \DOMElement
     *
     * @return \DOMElement
     */
    private function treeBuild(TreeNodeList $tree, \DOMElement $recordset) {
        $data = array_flip($this->data->getFieldByName($this->idFieldName)->getData());
        foreach ($tree as $id => $node) {
            if (isset($data[$id])) {
                //Идентификатор строки
                $num = $data[$id];
                $dom_record = $this->document->createElement('record');
                foreach ($this->dataDescription as $fieldName => $fieldDescription) {
                    $fieldProperties = [];
                    $fieldValue = '';

                    if ($f = $this->data->getFieldByName($fieldName)) {
                        $fieldValue = $this->data->getFieldByName($fieldName)->getRowData($num);
                        $fieldProperties = $this->data->getFieldByName($fieldName)->getRowProperties($num);
                        if ($fieldDescription->getType() == FieldDescription::FIELD_TYPE_SELECT) {
                            $fieldValue = $this->createOptions($fieldDescription, [$fieldValue]);
                        }
                    }
                    $dom_field = $this->createField($fieldName, $fieldDescription, $fieldValue, $fieldProperties);
                    $dom_record->appendChild($dom_field);
                }
                $recordset->appendChild($dom_record);
                if ($node->hasChildren()) {
                    $dom_record->appendChild($this->treeBuild($node->getChildren(), $this->document->createElement('recordset')));
                }
            }

        }
        return $recordset;
    }

    protected function createField($fieldName, FieldDescription $fieldInfo, $fieldValue = false, $fieldProperties = false) {
        foreach (
            [
                'nullable',
                'pattern',
                'message',
                'tabName',
                'tableName',
                'sort',
                'customField',
                //'deleteFileTitle',
                /*'msgOpenField',
                'msgCloseField',*/
                'default'
            ] as $propertyName
        ) {
            $fieldInfo->removeProperty($propertyName);
        }

        return parent::createField($fieldName, $fieldInfo, $fieldValue, $fieldProperties);
    }
}
