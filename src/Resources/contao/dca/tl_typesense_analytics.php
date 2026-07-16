<?php
/**
 * Copyright (C) 2022  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

use Krabo\TypesenseSearchBundle\DataContainer\Driver\DC_Typesense;
use Krabo\TypesenseSearchBundle\Typesense;

\Contao\System::loadLanguageFile('tl_typesense_analytics');

$GLOBALS['TL_DCA']['tl_typesense_analytics'] = array
(
  // Config
  'config' => array
  (
    'dataContainer'             => DC_Typesense::class,
    'switchToEdit'              => true,
    'sql'                       => array
    (
      'keys' => array
      (
        'id' => 'primary'
      )
    ),
    'onload_callback' => array(
      array('tl_typesense_analytics', 'onLoad'),
    ),
  ),

  // List
  'list' => array
  (
    'sorting' => array
    (
      'mode'                    => 2,
      'fields'                  => array('tstamp'),
      'flag'                    => 12,
      'panelLayout'             => 'sort,filter,date_search,search,limit',
      'panel_callback'          => [
        'date_search'           => ['tl_typesense_analytics', 'dateSearchPanel'],
      ]
    ),
    'label' => array
    (
      'showColumns'             => true,
      'fields'                  => array('q', 'result_count', 'click_through', 'tag'),
    ),
    'global_operations' => array
    (
      'tl_typesense_analytics_export' => array
      (
        'label'               =>  $GLOBALS['TL_LANG']['tl_typesense_analytics']['export'],
        'href'                => 'key=export',
        'class'               => 'tl_typesense_analytics_export',
        'attributes'          => 'onclick="Backend.getScrollOffset()" accesskey="c"',
        'icon'                => 'tablewizard.svg',
      ),
      'all' => array
      (
        'href'                => 'act=select',
        'class'               => 'header_edit_all',
        'attributes'          => 'onclick="Backend.getScrollOffset()" accesskey="e"'
      ),
    ),
    'operations' => array
    (
      'edit' => array
      (
        'href'                => 'act=edit',
        'icon'                => 'edit.svg',
      ),
      'delete' => array
      (
        'href'                => 'act=delete',
        'icon'                => 'delete.svg',
        'attributes'          => 'onclick="if(!confirm(\'' . $GLOBALS['TL_LANG']['tl_typesense_analytics']['deleteConfirm'] . '\'))return false;Backend.getScrollOffset()"',
      ),
    )
  ),

  // Palettes
  'palettes' => array
  (
    '__selector__'                => [],
    'default'                     => 'tstamp,q;result_count;result_detail;ip;click_through;tag'
  ),

  // Subpalettes
  'subpalettes' => array
  (
  ),

  // Fields
  'fields' => array
  (
    'id' => array
    (
      'sql'                     => "int(10) unsigned NOT NULL auto_increment"
    ),
    'tstamp' => array
    (
      'filter'                  => true,
      'sorting'                 => true,
      'flag'                    => 6,
      'inputType'               => 'text',
      'sql'                     => "int(10) unsigned NOT NULL default 0",
      'eval'                    => array('mandatory'=>true, 'rgxp'=>'datim', 'datepicker'=>true, 'tl_class'=>'w50 wizard'),
    ),
    'q' => array
    (
      'search'                  => true,
      'filter'                  => true,
      'inputType'               => 'text',
      'eval'                    => array('mandatory'=>true, 'maxlength'=>255, 'tl_class'=>'w50'),
      'sql'                     => "varchar(255) NOT NULL default ''",
    ),
    'result_count' => array
    (
      'search'                  => true,
      'filter'                  => true,
      'sorting'                 => true,
      'inputType'               => 'text',
      'flag'                    => 11,
      'eval'                    => array('mandatory'=>true, 'rgxp'=>'natural', 'tl_class'=>'w50'),
      'sql'                     => "int(10) NOT NULL default '0'",
    ),
    'result_detail' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>false, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'ip' => array
    (
      'search'                  => true,
      'inputType'               => 'text',
      'eval'                    => array('mandatory'=>false, 'maxlength'=>255, 'tl_class'=>'w50'),
      'sql'                     => "varchar(255) NOT NULL default ''",
    ),
    'tag' => array
    (
      'search'                  => true,
      'filter'                  => true,
      'sorting'                 => true,
      'inputType'               => 'text',
      'eval'                    => array('mandatory'=>false, 'maxlength'=>255, 'tl_class'=>'w50'),
      'sql'                     => "varchar(255) NOT NULL default ''",
    ),
    'click_through' => array
    (
      'search'               => true,
      'sorting'              => true,
      'filter'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>false, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
  )
);

class tl_typesense_analytics {

  public function onLoad(\Contao\DataContainer $dc)
  {
    /** @var AttributeBagInterface $objSessionBag */
    $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');
    $session = $objSessionBag->all();
    if (!empty($session['dateSearch'][$dc->table]['tstamp_start'])) {
      if ($dc instanceof \Krabo\TypesenseSearchBundle\DataContainer\Driver\DC_Typesense) {
        $dc->addWhere('tstamp >= ?', strtotime($session['dateSearch'][$dc->table]['tstamp_start']));
      }
    }
    if (!empty($session['dateSearch'][$dc->table]['tstamp_stop'])) {
      if ($dc instanceof \Krabo\TypesenseSearchBundle\DataContainer\Driver\DC_Typesense) {
        $dc->addWhere('tstamp <= ?', strtotime($session['dateSearch'][$dc->table]['tstamp_stop']));
      }
    }
  }

  public function dateSearchPanel(\Contao\DataContainer $dc) {
    $strTable = $dc->table;
    /** @var AttributeBagInterface $objSessionBag */
    $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');
    $session = $objSessionBag->all();
    // Store search value in the current session
    if (Input::post('FORM_SUBMIT') == 'tl_filters') {
      $session['dateSearch'][$strTable]['tstamp_start'] = Input::post('tstamp_start');
      $session['dateSearch'][$strTable]['tstamp_stop'] = Input::post('tstamp_stop');
      $objSessionBag->replace($session);
    }

    $panel = '<div class="tl_search tl_subpanel" style="width: 100%;"><strong>' . $GLOBALS['TL_LANG']['tl_typesense_analytics']['tstamp'][0] . ':</strong>';
    $panel .= '<div class="tl_subpanel" style="width: 200px">tot&nbsp;';
    $panel .= $this->createDateField('tstamp_stop', $session['dateSearch'][$strTable]['tstamp_stop']);
    $panel .= '</div>';
    $panel .= '<div class="tl_subpanel" style="width: 200px">van&nbsp;';
    $panel .= $this->createDateField('tstamp_start', $session['dateSearch'][$strTable]['tstamp_start']);
    $panel .= '</div>';
    $panel .= '</div>';

    return $panel;
  }

  protected function createDateField($name, $value): string {
      return '<input type="search" id="ctrl_' . $name . '" name="' . $name . '" class="tl_text datepicker" value="' . $value . '" style="width: 100px;">
      <img src="assets/datepicker/images/icon.svg" width="20" height="20" alt="" id="toggle_' . $name . '" style="vertical-align:-6px">
      <script>window.addEvent("domready", function() {
        new Picker.Date($$("#ctrl_' . $name . '"), {
          draggable:false,
          toggle:$$("#toggle_' . $name . '"),
          format:"' . \Contao\Date::formatToJs($GLOBALS['TL_CONFIG']['dateFormat']) . '",
          positionOffset:{x:-197,y:-182},
           pickerClass:"datepicker_bootstrap",
          useFadeInOut:!Browser.ie,
          startDay: ' . $GLOBALS['TL_LANG']['MSC']['weekOffset'] . ',
          titleFormat:"' . $GLOBALS['TL_LANG']['MSC']['titleFormat'] . '"
        });
      });</script>';
    }

}