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

use Krabo\TypesenseSearchBundle\Typesense;

\Contao\System::loadLanguageFile('tl_typesense_collection');

$GLOBALS['TL_DCA']['tl_typesense_collection'] = array
(
  // Config
  'config' => array
  (
    'dataContainer'             => 'Table',
    'switchToEdit'              => true,
    'sql'                       => array
    (
      'keys' => array
      (
        'id' => 'primary'
      )
    ),
  ),

  // List
  'list' => array
  (
    'sorting' => array
    (
      'mode'                    => 1,
      'fields'                  => array('name'),
      'flag'                    => 11,
      'panelLayout'             => 'sort,filter,search,limit'
    ),
    'label' => array
    (
      'showColumns'             => true,
      'fields'                  => array('label', 'name'),
    ),
    'global_operations' => array
    (
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
        'attributes'          => 'onclick="if(!confirm(\'' . $GLOBALS['TL_LANG']['tl_typesense_collection']['deleteConfirm'] . '\'))return false;Backend.getScrollOffset()"',
      ),
    )
  ),

  // Palettes
  'palettes' => array
  (
    '__selector__'                => [],
    'default'                     => 'name,label;query_settings;header_template;header_advanced_template;item_template;item_advanced_template;footer_template;footer_advanced_template;no_results_template'
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
      'sql'                     => "int(10) unsigned NOT NULL default 0"
    ),
    'name' => array
    (
      'search'                  => true,
      'inputType'               => 'select',
      'eval'                    => array('mandatory'=>true, 'maxlength'=>255, 'tl_class'=>'w50'),
      'sql'                     => "varchar(255) NOT NULL default ''",
      'options_callback'        => function($dc) {
        $typesense = Typesense::getInstance();
        if (!$typesense->isEnabled()) {
          return [];
        }
        return $typesense->getCollections(true);
      }
    ),
    'label' => array
    (
      'search'                  => true,
      'inputType'               => 'text',
      'eval'                    => array('mandatory'=>true, 'maxlength'=>255, 'tl_class'=>'w50'),
      'sql'                     => "varchar(255) NOT NULL default ''",
    ),
    'query_settings' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>true, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'header_template' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>true, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'header_advanced_template' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>true, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'item_template' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>true, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'item_advanced_template' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>true, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'footer_template' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>false, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'footer_advanced_template' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>false, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
    'no_results_template' => array
    (
      'exclude'               => true,
      'inputType'             => 'textarea',
      'eval'                    => array('allowHtml'=>true, 'class'=>'monospace', 'rte'=>'ace'),
      'sql'                   => 'text NULL',
    ),
  )
);