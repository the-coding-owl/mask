<?php

namespace MASK\Mask\Domain\Repository;

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2010-2013 Extbase Team (http://forge.typo3.org/projects/typo3v4-mvc)
 *  Extbase is a backport of TYPO3 Flow. All credits go to the TYPO3 Flow team.
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * ************************************************************* */

use MASK\Mask\Utility\GeneralUtility as MaskUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;

/**
 * Repository for \TYPO3\CMS\Extbase\Domain\Model\Tca.
 *
 * @api
 */
class StorageRepository
{

    /**
     * FieldHelper
     *
     * @var \MASK\Mask\Helper\FieldHelper
     */
    protected $fieldHelper;

    /**
     * SqlCodeGenerator
     *
     * @var \MASK\Mask\CodeGenerator\SqlCodeGenerator
     */
    protected $sqlCodeGenerator;

    /**
     * SettingsService
     *
     * @var \MASK\Mask\Domain\Service\SettingsService
     */
    protected $settingsService;

    /**
     * settings
     *
     * @var array
     */
    protected $extSettings;

    /**
     * json configuration
     * @var array
     */
    private static $json = null;

    /**
     * @var Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * is called before every action
     */
    public function __construct()
    {
        $this->settingsService = GeneralUtility::makeInstance('MASK\\Mask\\Domain\\Service\\SettingsService');
        $this->extSettings = $this->settingsService->get();
    }

    /**
     * Load Storage
     *
     * @return array
     */
    public function load()
    {
        if (self::$json === null) {
            self::$json = array();
            $this->emitBeforeLoadEvent();
            if (!empty($this->extSettings['json'])) {
                $file = MaskUtility::getFileAbsFileName($this->extSettings['json']);
                if (file_exists($file)) {
                    self::$json = json_decode(file_get_contents($file), true);
                }
            }
            $this->emitAfterLoadEvent();
        }
        return self::$json;
    }

    /**
     * Write Storage
     *
     * @param $json
     * @return void
     */
    public function write($json)
    {
        if (!empty($this->extSettings['json'])) {
            $json = $this->emitBeforeWriteEvent($json);
            if (!empty($json)) {
                $file = MaskUtility::getFileAbsFileName($this->extSettings['json']);
                GeneralUtility::writeFile(
                    $file,
                    json_encode($json, JSON_PRETTY_PRINT)
                );
            }
            $json = $this->emitAfterWriteEvent($json);
        }
        self::$json = $json;
    }

    /**
     * Load Field
     * @author Benjamin Butschell <bb@webprofil.at>
     * @return array
     */
    public function loadField($type, $key)
    {
        $json = $this->load();
        return $json[$type]["tca"][$key];
    }

    /**
     * Loads all the inline fields of an inline-field, recursively!
     *
     * @param string $parentKey key of the inline-field
     * @author Benjamin Butschell <bb@webprofil.at>
     * @return array
     */
    public function loadInlineFields($parentKey)
    {
        $json = $this->load();
        $inlineFields = array();
        foreach ($json as $table) {
            if ($table["tca"]) {
                foreach ($table["tca"] as $key => $tca) {
                    if ($tca["inlineParent"] == $parentKey) {
                        if ($tca["config"]["type"] == "inline") {
                            $tca["inlineFields"] = $this->loadInlineFields($key);
                        }
                        $tca["maskKey"] = "tx_mask_" . $tca["key"];
                        $inlineFields[] = $tca;
                    }
                }
            }
        }
        return $inlineFields;
    }

    /**
     * Load Element with all the field configurations
     *
     * @return array
     */
    public function loadElement($type, $key)
    {
        [$type, $key] = $this->emitBeforeLoadElementEvent($type, $key);
        $json = $this->load();
        $fields = array();
        $columns = $json[$type]["elements"][$key]["columns"];

        //Check if it is an array before trying to count it
        if (is_array($columns) && count($columns) > 0) {
            foreach ($columns as $fieldName) {
                $fields[$fieldName] = $json[$type]["tca"][$fieldName];
            }
        }
        if (count($fields) > 0) {
            $json[$type]["elements"][$key]["tca"] = $fields;
        }
        $json = $this->emitAfterLoadElementEvent($json);
        return $json[$type]["elements"][$key];
    }

    /**
     * Adds new Content-Element
     *
     * @param array $content
     */
    public function add($content)
    {
        $content = $this->emitBeforeAddEvent($content);
        if (!empty($content)) {
            // Load
            $json = $this->load();

            // Create JSON elements Array:
            foreach ($content["elements"] as $key => $value) {
                // delete columns and labels of irre-fields from elements
                if ($key == "columns" || $key == "labels") {
                    foreach ($value as $index => $column) {
                        if (!$content["tca"][$index]["inlineParent"]) {
                            $contentColumns[] = $column;
                        } else {
                            unset($value[$index]);
                            unset($value[$index]);
                        }
                        if ($key === 'labels'
                            && empty($column)
                            && isset($json[$content['type']]['tca'][$content['elements']['columns'][$index]])
                        ) {
                            // If using a mask field with empty label, we have to set the "default" label
                            $label = '';
                            foreach ($json[$content['type']]['elements'] as $element) {
                                if (in_array($content['elements']['columns'][$index], $element['columns'], TRUE)) {
                                    $i = array_search(
                                        $content['elements']['columns'][$index], $element['columns'], TRUE
                                    );
                                    if (!empty($element['labels'][$i])) {
                                        $label = $element['labels'][$i];
                                        break;
                                    }
                                }
                            }
                            $value[$index] = $label;
                        }
                    }
                }
                $json[$content["type"]]["elements"][$content["elements"]["key"]][$key] = $value;
            }

            $contentColumns = [];
            $columns = [];

            // delete columns and labels of irre-fields from elements
            if ($content["elements"]["columns"]) {
                foreach ($content["elements"]["columns"] as $index => $column) {
                    if (!$content["tca"][$index]["inlineParent"]) {
                        $contentColumns[] = $column;
                    } else {
                        unset($content["elements"]["columns"][$index]);
                        unset($content["elements"]["labels"][$index]);
                    }
                    $columns[] = $column;
                }
            }

            // Create JSON sql Array:
            if (is_array($content["sql"])) {
                foreach ($content["sql"] as $table => $sqlArray) {
                    foreach ($sqlArray as $index => $type) {
                        $fieldname = "tx_mask_" . $columns[$index];
                        $json[$table]["sql"][$fieldname][$table][$fieldname] = $type;
                    }
                }
            }

            // Create JSON tca Array:
            if (is_array($content["tca"])) {


                foreach ($content["tca"] as $key => $value) {
                    $inlineField = FALSE;

                    // if this field is inline-field
                    if ($value["inlineParent"]) {
                        $type = $value["inlineParent"];
                        $inlineField = TRUE;
                    } else {
                        $type = $content["type"];
                    }

                    $json[$type]["tca"][$columns[$key]] = $value;

                    // add rte flag if inline and rte
                    if ($inlineField) {
                        if ($content["elements"]["options"][$key] == "rte") {
                            $json[$type]["tca"][$columns[$key]]["rte"] = "1";
                        }
                    }

                    // Only add columns to elements if it is no inlinefield
                    if (!$inlineField) {
                        $json[$type]["elements"][$content["elements"]["key"]]["columns"][$key] = "tx_mask_" . $columns[$key];
                    }
                    $json[$type]["tca"]["tx_mask_" . $columns[$key]] = $json[$type]["tca"][$columns[$key]];
                    $json[$type]["tca"]["tx_mask_" . $columns[$key]]["key"] = $columns[$key];

                    if ($inlineField) {
                        $json[$type]["tca"]["tx_mask_" . $columns[$key]]["order"] = $key;
                    }

                    unset($json[$type]["tca"][$columns[$key]]);
                }
            }

            // sort content elements by key before saving
            $this->sortJson($json);
            $this->write($json);
        }
        $this->emitAfterAddEvent($json);
    }

    /**
     * Removes Content-Element
     *
     * @param string $type
     * @param string $key
     * @param array $remainingFields
     */
    public function remove($type, $key, $remainingFields = array())
    {
        [$type, $key, $remainingFields] = $this->emitBeforeRemoveEvent($type, $key, $remainingFields);
        if (!empty($type) && !empty($key) && !empty($remainingFields)) {
            // Load
            $json = $this->load();

            // Remove
            $columns = $json[$type]["elements"][$key]["columns"];
            unset($json[$type]["elements"][$key]);
            if (is_array($columns)) {
                foreach ($columns as $field) {
                    $json = $this->removeField($type, $field, $json, $remainingFields);
                }
            }
            $this->sortJson($json);
            $this->write($json);
        }
        $this->emitAfterRemoveEvent($json);
    }

    /**
     * Hides Content-Element
     *
     * @param string $type
     * @param string $key
     */
    public function hide($type, $key)
    {
        [$type, $key] = $this->emitBeforeHideEvent($type, $key);
        if (!empty($type) && !empty($key)) {
            // Load
            $json = $this->load();
            $json[$type]["elements"][$key]["hidden"] = 1;
            $this->sortJson($json);
            $this->write($json);
        }
        $this->emitAfterHideEvent($json);
    }

    /**
     * Activates Content-Element
     *
     * @param string $type
     * @param string $key
     */
    public function activate($type, $key)
    {
        [$type, $key] = $this->emitBeforeActivateEvent($type, $key);
        if (!empty($type) && !empty($key)) {
            // Load
            $json = $this->load();
            unset($json[$type]["elements"][$key]["hidden"]);
            $this->sortJson($json);
            $this->write($json);
        }
        $this->emitAfterActivateEvent($json);
    }

    /**
     * Removes a field from the json, also recursively all inline-fields
     * @author Benjamin Butschell <bb@webprofil.at>
     *
     * @param string $table
     * @param string $field
     * @param array $json
     * @param array $remainingFields
     * @return array
     */
    private function removeField($table, $field, $json, $remainingFields = array())
    {

        $this->fieldHelper = GeneralUtility::makeInstance('MASK\\Mask\\Helper\\FieldHelper');

        // check if this field is used in any other elements
        $elementsInUse = array();
        if ($json[$table]["elements"]) {
            foreach ($json[$table]["elements"] as $element) {
                if ($element["columns"]) {
                    foreach ($element["columns"] as $column) {
                        if ($column == $field) {
                            $elementsInUse[] = $element;
                        }
                    }
                }
            }
        }


        // check if father gets deleted
        $fatherFound = false;
        if ($remainingFields) {
            foreach ($remainingFields as $remainingField) {
                if ($field == "tx_mask_" . $remainingField) {
                    $fatherFound = true;
                }
            }
        }
        $fatherGetsDeleted = !$fatherFound;

        // if the field is a repeating field, make some exceptions
        if ($json[$table]["tca"][$field]["config"]["type"] == "inline") {
            $inlineFields = $this->loadInlineFields($field);
            if ($inlineFields) {
                // Recursively delete all inline-fields if necessary
                foreach ($inlineFields as $inlineField) {
                    $found = false;
                    // check if the fields are really deleted, or if they are just deleted temporarly for update action
                    if ($remainingFields) {
                        foreach ($remainingFields as $remainingField) {
                            if ($inlineField["key"] == $remainingField) {
                                $found = true;
                            }
                        }
                    }
                    if ($found) {
                        // was not really deleted => can be deleted temporarly because it will be readded
                        $json = $this->removeField($inlineField["inlineParent"], "tx_mask_" . $inlineField["key"],
                            $json);
                    } else {
                        // was really deleted and can only be deleted if father is not in use in another element
                        if (($fatherGetsDeleted && count($elementsInUse) == 0) || !$fatherGetsDeleted) {
                            $json = $this->removeField($inlineField["inlineParent"], "tx_mask_" . $inlineField["key"],
                                $json);
                        }
                    }
                }
            }
        }

        // then delete the field, if it is not in use in another element
        if (count($elementsInUse) < 1) {
            unset($json[$table]["tca"][$field]);
            unset($json[$table]["sql"][$field]);

            // If field is of type file, also delete entry in sys_file_reference
            if ($this->fieldHelper->getFormType($field) == "File") {
                unset($json["sys_file_reference"]["sql"][$field]);
                $json = $this->cleanTable("sys_file_reference", $json);
            }
        }
        return $this->cleanTable($table, $json);
    }

    /**
     * Deletes all the empty settings of a table
     *
     * @author Benjamin Butschell <bb@webprofil.at>
     * @param string $table
     * @param array $json
     * @return array
     */
    private function cleanTable($table, $json)
    {
        if ($json[$table]["tca"] && count($json[$table]["tca"]) < 1) {
            unset($json[$table]["tca"]);
        }
        if ($json[$table]["sql"] && count($json[$table]["sql"]) < 1) {
            unset($json[$table]["sql"]);
        }
        if ($json[$table] && count($json[$table]) < 1) {
            unset($json[$table]);
        }
        return $json;
    }

    /**
     * Updates Content-Element in Storage-Repository
     *
     * @param array $content
     */
    public function update($content)
    {
        $content = $this->emitBeforeUpdateEvent($content);
        if (!empty($content)) {
            $this->remove($content["type"], $content["orgkey"], $content["elements"]["columns"]);
            $this->add($content);
        }
        $this->emitAfterUpdateEvent($content);
    }

    /**
     * Sorts the json entries
     * @param array $json
     */
    private function sortJson(array &$array)
    {
        // check if array is not a hash table, because we only want to sort hash tables
        if (
            [] === $array
            || !(array_keys($array) !== range(0, count($array) - 1))
        ) {
            return false;
        }

        ksort($array);
        foreach ($array as &$item) {
            if (is_array($item)) {
                $this->sortJson($item);
            }
        }
    }

    /**
     * Emit the "beforeLoad" event
     *
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeLoadEvent()
    {
        self::$json = $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeLoad',
            ['json' => self::$json]
        )['json'];
    }

    /**
     * Emit the "afterLoad" event
     *
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterLoadEvent()
    {
        self::$json = $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterLoad',
            ['json' => self::$json]
        )['json'];
    }

    /**
     * Emit the "beforeWrite" event
     *
     * @param array $json
     * @return array
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeWriteEvent(array $json): array
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeWrite',
            ['json' => $json]
        )['json'];
    }

    /**
     * Emit the "afterWrite" event
     *
     * @param array $json
     * @return array
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterWriteEvent(array $json): array
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterWrite',
            ['json' => $json]
        )['json'];
    }

    /**
     * Emit the "beforeAdd" event
     *
     * @param array $content
     * @return mixed
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeAddEvent(array $content)
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeAdd',
            ['content' => $content]
        )['content'];
    }

    /**
     * Emit the "afterAdd" event
     *
     * @param array|null $json
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterAddEvent(?array $json)
    {
        self::$json = $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterAdd',
            ['json' => $json]
        )['json'];
    }

    /**
     * Emit the "beforeRemove" event
     *
     * @param string $type
     * @param string $key
     * @param array $remainingFields
     * @return array|mixed
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeRemoveEvent(string $type, string $key, array $remainingFields)
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeRemove',
            ['type' => $type, 'key' => $key, 'remainingFields' => $remainingFields]
        );
    }

    /**
     * Emit the "afterRemove" event
     *
     * @param array|null $json
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterRemoveEvent(?array $json)
    {
        self::$json = $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterRemove',
            ['json' => $json]
        )['json'];
    }

    /**
     * Emit the "beforeHide" event
     *
     * @param string $type
     * @param string $key
     * @return array|mixed
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeHideEvent(string $type, string $key)
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeHide',
            ['type' => $type, 'key' => $key]
        );
    }

    /**
     * Emit the "afterHide" event
     *
     * @param array|null $json
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterHideEvent(?array $json)
    {
        self::$json = $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterHide',
            ['json' => $json]
        )['json'];
    }

    /**
     * Emit the "beforeActivate" event
     *
     * @param string $type
     * @param string $key
     * @return array|mixed
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeActivateEvent(string $type, string $key)
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeActivate',
            ['type' => $type, 'key' => $key]
        );
    }

    /**
     * Emit the "afterActivate" event
     *
     * @param array|null $json
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterActivateEvent(?array $json)
    {
        self::$json = $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterActivate',
            ['json' => $json]
        )['json'];
    }

    /**
     * Emit the "beforeUpdate" event
     *
     * @param array $content
     * @return mixed
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeUpdateEvent(array $content)
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeUpdate',
            ['content' => $content]
        )['content'];
    }

    /**
     * Emit the "afterUpdate" event
     *
     * @param $content
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterUpdateEvent($content)
    {
        $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterUpdate',
            ['content' => $content]
        );
    }

    /**
     * Emit the "beforeElementLoad" event
     *
     * @param string $type
     * @param string $key
     * @return array|mixed
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitBeforeLoadElementEvent(string $type, string $key)
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'beforeLoadElement',
            ['type' => $type, 'key' => $key]
        );
    }

    /**
     * Emit the "afterLoadElement" event
     *
     * @param array|null $json
     * @return array|mixed
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    protected function emitAfterLoadElementEvent(?array $json)
    {
        return $this->getSignalSlotDispatcher()->dispatch(
            __CLASS__,
            'afterLoadElement',
            ['json' => $json]
        )['json'];
    }

    /**
     * Get the SignalSlot dispatcher
     *
     * @return Dispatcher
     */
    protected function getSignalSlotDispatcher()
    {
        if (!isset($this->signalSlotDispatcher)) {
            $this->signalSlotDispatcher = GeneralUtility::makeInstance(ObjectManager::class)->get(Dispatcher::class);
        }
        return $this->signalSlotDispatcher;
    }
}
